<?php

namespace Tests\Concerns;

use App\Enums\MappingOrigin;
use App\Enums\MatchClass;
use App\Enums\MatchConfidence;
use App\Enums\OfferAvailability;
use App\Enums\SupplierAccountStatus;
use App\Enums\SupplierRefreshCadence;
use App\Enums\SupplierSourceMethod;
use App\Models\CanonicalProduct;
use App\Models\ImportFile;
use App\Models\ImportRun;
use App\Models\NormalizedImportRecord;
use App\Models\Organization;
use App\Models\PriceObservation;
use App\Models\RawImportRecord;
use App\Models\RestaurantLocation;
use App\Models\SupplierAccount;
use App\Models\SupplierDefinition;
use App\Models\SupplierOffer;
use App\Models\User;
use App\Models\VendorItem;
use App\Models\VendorItemMapping;
use Illuminate\Support\Str;

trait BuildsComparisonFixtures
{
    private function comparisonAccount(
        Organization $organization,
        RestaurantLocation $location,
        string $name,
        SupplierRefreshCadence $cadence = SupplierRefreshCadence::Weekly,
    ): SupplierAccount {
        $definition = SupplierDefinition::factory()->create(['name' => $name, 'code' => Str::slug($name).'-'.Str::lower(Str::random(6))]);

        return SupplierAccount::query()->create([
            'organization_id' => $organization->id,
            'restaurant_location_id' => $location->id,
            'supplier_definition_id' => $definition->id,
            'display_name' => $name,
            'masked_account_reference' => null,
            'source_method' => SupplierSourceMethod::ManualImport,
            'expected_refresh_cadence' => $cadence,
            'status' => SupplierAccountStatus::Active,
        ]);
    }

    /**
     * @param  array{price?: string, quantity?: string|null, normalized_unit?: string|null, dimension?: string|null, availability?: OfferAvailability, observed_at?: \DateTimeInterface, match_class?: MatchClass, description?: string, brand?: string|null, sku?: string}  $overrides
     */
    private function comparisonOffer(
        SupplierAccount $account,
        CanonicalProduct $product,
        array $overrides = [],
    ): SupplierOffer {
        $sku = $overrides['sku'] ?? 'SKU-'.Str::upper(Str::random(8));
        $description = $overrides['description'] ?? 'TOM BEEF 5X6 1/25 LB CS';
        $run = ImportRun::query()->create([
            'organization_id' => $account->organization_id,
            'restaurant_location_id' => $account->restaurant_location_id,
            'supplier_account_id' => $account->id,
            'created_by' => User::factory()->create()->id,
            'fingerprint' => hash('sha256', (string) Str::uuid7()),
            'request_id' => 'comparison-fixture-'.Str::uuid7(),
        ]);
        $file = ImportFile::query()->create([
            'organization_id' => $account->organization_id,
            'import_run_id' => $run->id,
            'original_filename' => 'comparison.csv',
            'storage_key' => 'comparison/'.Str::uuid7(),
            'size_bytes' => 1,
            'media_type' => 'text/csv',
            'format' => 'csv',
        ]);
        $raw = RawImportRecord::query()->create([
            'organization_id' => $account->organization_id,
            'import_run_id' => $run->id,
            'import_file_id' => $file->id,
            'source_row' => 2,
            'raw_values' => ['SKU' => $sku, 'Description' => $description],
            'mapped_values' => [
                'supplier_sku' => $sku,
                'description' => $description,
                'pack_size_text' => '1/25 LB CS',
                'price' => $overrides['price'] ?? '24.50',
                'currency' => 'USD',
                'observed_at' => ($overrides['observed_at'] ?? now())->format(\DateTimeInterface::ATOM),
                'availability' => ($overrides['availability'] ?? OfferAvailability::Available)->value,
            ],
            'status' => 'normalized',
        ]);
        $normalized = NormalizedImportRecord::query()->create([
            'organization_id' => $account->organization_id,
            'import_run_id' => $run->id,
            'raw_import_record_id' => $raw->id,
            'parser_version' => '1.0.0',
            'normalized_values' => ['packs' => [], 'measurements' => [], 'case_quantity' => null, 'size_code' => '5X6', 'variable_weight' => false],
            'warnings' => [],
            'confidence' => 'high',
        ]);
        $vendor = VendorItem::query()->create([
            'organization_id' => $account->organization_id,
            'supplier_account_id' => $account->id,
            'first_normalized_import_record_id' => $normalized->id,
            'last_normalized_import_record_id' => $normalized->id,
            'supplier_item_identifier' => $sku,
            'identity_key' => 'sku:'.$sku,
            'raw_description' => $description,
            'normalized_description' => 'tomato beef 5x6 25 lb',
            'brand' => $overrides['brand'] ?? null,
            'normalized_attributes' => ['size_code' => '5X6', 'dimensions' => [$overrides['dimension'] ?? 'weight']],
            'parser_version' => '1.0.0',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        VendorItemMapping::query()->create([
            'vendor_item_id' => $vendor->id,
            'canonical_product_id' => $product->id,
            'product_variant_id' => null,
            'match_candidate_id' => null,
            'reviewed_by' => null,
            'supersedes_id' => null,
            'match_class' => $overrides['match_class'] ?? MatchClass::EquivalentProduct,
            'confidence' => MatchConfidence::B,
            'origin' => MappingOrigin::Manual,
            'matcher_version' => 'catalog-matcher-1.0.0',
            'evidence' => ['stage' => 'comparison_fixture', 'size_code' => '5X6'],
            'is_current' => true,
            'verified_at' => now(),
        ]);
        $offer = SupplierOffer::query()->create([
            'organization_id' => $account->organization_id,
            'restaurant_location_id' => $account->restaurant_location_id,
            'supplier_account_id' => $account->id,
            'vendor_item_id' => $vendor->id,
            'is_active' => true,
        ]);
        $quantity = array_key_exists('quantity', $overrides) ? $overrides['quantity'] : '11339.80925';
        $normalizedUnit = $quantity === null ? null : ($overrides['normalized_unit'] ?? 'g');
        $dimension = $quantity === null ? null : ($overrides['dimension'] ?? 'weight');
        $observation = PriceObservation::query()->create([
            'organization_id' => $account->organization_id,
            'supplier_offer_id' => $offer->id,
            'import_run_id' => $run->id,
            'normalized_import_record_id' => $normalized->id,
            'case_price' => $overrides['price'] ?? '24.50',
            'currency' => 'USD',
            'source_price_evidence' => ['source_price' => $overrides['price'] ?? '24.50', 'source_currency' => 'USD'],
            'source_pack_evidence' => ['packs' => [['original_text' => '1/25 LB CS']], 'warnings' => []],
            'normalized_quantity' => $quantity,
            'normalized_unit' => $normalizedUnit,
            'dimension' => $dimension,
            'availability' => $overrides['availability'] ?? OfferAvailability::Available,
            'observed_at' => $overrides['observed_at'] ?? now(),
            'formula_version' => 'price-comparison-1.0.0',
        ]);
        $offer->update(['current_price_observation_id' => $observation->id]);

        return $offer->refresh();
    }
}
