<?php

namespace Tests\Feature;

use App\Models\ImportFile;
use App\Models\ImportRun;
use App\Models\NormalizedImportRecord;
use App\Models\PriceObservation;
use App\Models\RawImportRecord;
use App\Models\SupplierAccount;
use App\Models\User;
use App\Services\SearchComparisonCache;
use App\Services\SupplierOfferProjectionService;
use App\Services\VendorItemProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SupplierOfferProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_imports_reuse_offer_identity_and_preserve_immutable_price_history(): void
    {
        $account = SupplierAccount::factory()->create();
        $first = $this->normalized($account, 'TOM-BEEF-25', '24.50', 'USD', now()->subDays(2));
        $second = $this->normalized($account, 'TOM-BEEF-25', '23.00', 'USD', now());
        $vendors = app(VendorItemProjectionService::class);
        $offers = app(SupplierOfferProjectionService::class);
        $cache = app(SearchComparisonCache::class);

        $firstOffer = $offers->project($first, $vendors->project($first));
        $resolutions = 0;
        $cache->rememberSearch($account->organization_id, $account->restaurant_location_id, ['query' => 'beef tomato'], function () use (&$resolutions): array {
            $resolutions++;

            return ['results' => []];
        });
        $secondOffer = $offers->project($second, $vendors->project($second));
        $cache->rememberSearch($account->organization_id, $account->restaurant_location_id, ['query' => 'beef tomato'], function () use (&$resolutions): array {
            $resolutions++;

            return ['results' => []];
        });

        $this->assertSame($firstOffer->id, $secondOffer->id);
        $this->assertDatabaseCount('supplier_offers', 1);
        $this->assertDatabaseCount('price_observations', 2);
        $this->assertSame('23.000000', $secondOffer->currentPriceObservation->case_price);
        $this->assertSame('11339.809250000000', $secondOffer->currentPriceObservation->normalized_quantity);
        $this->assertSame('weight', $secondOffer->currentPriceObservation->dimension);
        $this->assertSame(SupplierOfferProjectionService::FORMULA_VERSION, $secondOffer->currentPriceObservation->formula_version);
        $this->assertSame('24.50', PriceObservation::query()->oldest('observed_at')->firstOrFail()->source_price_evidence['source_price']);
        $this->assertSame(2, $resolutions);

        $this->expectException(\LogicException::class);
        PriceObservation::query()->oldest('observed_at')->firstOrFail()->update(['case_price' => '1.000000']);
    }

    public function test_ambiguous_or_variable_weight_evidence_never_manufactures_a_normalized_denominator(): void
    {
        $account = SupplierAccount::factory()->create();
        $normalized = $this->normalized($account, 'TOM-CW', '28.00', 'USD', now(), true);
        $vendor = app(VendorItemProjectionService::class)->project($normalized);
        $offer = app(SupplierOfferProjectionService::class)->project($normalized, $vendor);
        $observation = $offer->currentPriceObservation;

        $this->assertNotNull($observation);
        $this->assertNull($observation->normalized_quantity);
        $this->assertNull($observation->normalized_unit);
        $this->assertNull($observation->dimension);
        $this->assertSame('unknown', $observation->availability->value);
    }

    public function test_missing_currency_keeps_offer_identity_without_inventing_a_price_observation(): void
    {
        $account = SupplierAccount::factory()->create();
        $normalized = $this->normalized($account, 'TOM-NO-CURRENCY', '28.00', '', now());
        $vendor = app(VendorItemProjectionService::class)->project($normalized);
        $offer = app(SupplierOfferProjectionService::class)->project($normalized, $vendor);

        $this->assertNull($offer->current_price_observation_id);
        $this->assertDatabaseCount('supplier_offers', 1);
        $this->assertDatabaseCount('price_observations', 0);
    }

    public function test_missing_source_observation_timestamp_keeps_offer_identity_without_using_processing_time(): void
    {
        $account = SupplierAccount::factory()->create();
        $normalized = $this->normalized($account, 'TOM-NO-TIMESTAMP', '28.00', 'USD', now(), false, false);
        $vendor = app(VendorItemProjectionService::class)->project($normalized);
        $offer = app(SupplierOfferProjectionService::class)->project($normalized, $vendor);

        $this->assertNull($offer->current_price_observation_id);
        $this->assertDatabaseCount('supplier_offers', 1);
        $this->assertDatabaseCount('price_observations', 0);
    }

    private function normalized(
        SupplierAccount $account,
        string $sku,
        string $price,
        string $currency,
        \DateTimeInterface $observedAt,
        bool $variableWeight = false,
        bool $includeObservedAt = true,
    ): NormalizedImportRecord {
        $run = ImportRun::query()->create([
            'organization_id' => $account->organization_id,
            'restaurant_location_id' => $account->restaurant_location_id,
            'supplier_account_id' => $account->id,
            'created_by' => User::factory()->create()->id,
            'fingerprint' => hash('sha256', (string) Str::uuid7()),
            'request_id' => 'offer-projection-'.Str::uuid7(),
        ]);
        $file = ImportFile::query()->create([
            'organization_id' => $account->organization_id,
            'import_run_id' => $run->id,
            'original_filename' => 'offers.csv',
            'storage_key' => 'offers/'.Str::uuid7(),
            'size_bytes' => 1,
            'media_type' => 'text/csv',
            'format' => 'csv',
        ]);
        $mappedValues = [
            'supplier_sku' => $sku,
            'description' => 'TOM BEEF 5X6 1/25 LB CS',
            'pack_size_text' => '1/25 LB CS',
            'price' => $price,
            'currency' => $currency,
        ];
        if ($includeObservedAt) {
            $mappedValues['observed_at'] = $observedAt->format(\DateTimeInterface::ATOM);
        }
        $raw = RawImportRecord::query()->create([
            'organization_id' => $account->organization_id,
            'import_run_id' => $run->id,
            'import_file_id' => $file->id,
            'source_row' => 2,
            'raw_values' => ['SKU' => $sku, 'Description' => 'TOM BEEF 5X6 1/25 LB CS'],
            'mapped_values' => $mappedValues,
            'status' => 'normalized',
        ]);
        $warning = ['code' => 'variable_weight', 'field' => 'pack_size_text', 'message' => 'A fixed quantity is unavailable.'];

        return NormalizedImportRecord::query()->create([
            'organization_id' => $account->organization_id,
            'import_run_id' => $run->id,
            'raw_import_record_id' => $raw->id,
            'parser_version' => '1.0.0',
            'normalized_values' => [
                'measurements' => [],
                'packs' => [[
                    'field' => 'pack_size_text',
                    'original_text' => '1/25 LB CS',
                    'original_count' => '1',
                    'pack_count' => '1',
                    'unit_size' => [
                        'field' => 'pack_size_text',
                        'original_text' => '25 LB',
                        'original_value' => '25',
                        'original_unit' => 'lb',
                        'dimension' => 'weight',
                        'value' => '25',
                        'unit' => 'lb',
                        'normalized_value' => '11339.80925',
                        'normalized_unit' => 'g',
                    ],
                    'case_total' => '11339.80925',
                    'case_total_unit' => 'g',
                ]],
                'case_quantity' => null,
                'size_code' => '5X6',
                'variable_weight' => $variableWeight,
                'warnings' => $variableWeight ? [$warning] : [],
            ],
            'warnings' => $variableWeight ? [$warning] : [],
            'confidence' => $variableWeight ? 'low' : 'high',
            'created_at' => $observedAt,
            'updated_at' => $observedAt,
        ]);
    }
}
