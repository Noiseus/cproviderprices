<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CanonicalProduct;
use App\Models\ImportFile;
use App\Models\ImportRun;
use App\Models\NormalizedImportRecord;
use App\Models\Organization;
use App\Models\PriceObservation;
use App\Models\ProductVariant;
use App\Models\RawImportRecord;
use App\Models\RestaurantLocation;
use App\Models\SavedList;
use App\Models\SavedListItem;
use App\Models\SupplierOffer;
use App\Models\User;
use App\Services\SavedListService;
use App\Tenancy\TenantContext;
use Database\Seeders\CatalogTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsComparisonFixtures;
use Tests\Concerns\BuildsTenantContext;
use Tests\TestCase;

final class SavedListTest extends TestCase
{
    use BuildsComparisonFixtures;
    use BuildsTenantContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogTaxonomySeeder::class);
    }

    public function test_owner_and_buyer_can_create_lists_but_viewers_are_read_only(): void
    {
        [$organization, $location] = $this->context();
        $owner = User::factory()->create();
        $buyer = User::factory()->create();
        $viewer = User::factory()->create();
        $this->membership($owner, $organization, Role::OrganizationOwner);
        $this->membership($buyer, $organization, Role::Buyer);
        $this->membership($viewer, $organization, Role::Viewer);
        $path = "/api/v1/organizations/{$organization->id}/saved-lists";

        $this->asUser($viewer)->postJson($path, ['name' => 'Viewer list', 'location_id' => $location->id])
            ->assertForbidden();
        $this->asUser($buyer)->postJson($path, ['name' => 'Buyer list', 'location_id' => $location->id])
            ->assertCreated()
            ->assertJsonPath('saved_list.name', 'Buyer list')
            ->assertJsonPath('saved_list.status', 'active');
        $this->asUser($owner)->postJson($path, ['name' => 'Owner list', 'location_id' => $location->id])
            ->assertCreated();
        $this->assertDatabaseCount('saved_lists', 2);
    }

    public function test_list_lifecycle_duplicate_and_audit_are_explicit(): void
    {
        [$organization, $location] = $this->context();
        $buyer = $this->buyer($organization);
        $list = $this->createList($buyer, $organization, $location, 'Friday prep');
        $path = "/api/v1/organizations/{$organization->id}/saved-lists/{$list['id']}";

        $renamed = $this->asUser($buyer)->patchJson($path, ['name' => 'Saturday prep', 'expected_version' => 1])
            ->assertOk()->json('saved_list');
        $copy = $this->asUser($buyer)->postJson("{$path}/duplicate", ['expected_version' => $renamed['version']])
            ->assertCreated()->json('saved_list');
        $archived = $this->asUser($buyer)->postJson("{$path}/archive", ['expected_version' => $renamed['version']])
            ->assertOk()->json('saved_list');
        $restored = $this->asUser($buyer)->postJson("{$path}/restore", ['expected_version' => $archived['version']])
            ->assertOk()->json('saved_list');
        $this->asUser($buyer)->deleteJson($path, ['expected_version' => $restored['version']])
            ->assertOk();

        $this->assertDatabaseMissing('saved_lists', ['id' => $list['id']]);
        $this->assertDatabaseHas('saved_lists', ['id' => $copy['id'], 'status' => 'active']);
        foreach (['saved_list.created', 'saved_list.renamed', 'saved_list.duplicated', 'saved_list.archived', 'saved_list.restored', 'saved_list.deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'action' => $action]);
        }
    }

    public function test_items_keep_canonical_intent_support_mutation_and_have_stable_ordering(): void
    {
        [$organization, $location, $product] = $this->context(true);
        $buyer = $this->buyer($organization);
        $first = $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Selected supplier'), $product, ['sku' => 'LIST-SELECTED', 'price' => '30.00']);
        $second = $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Best supplier'), $product, ['sku' => 'LIST-BEST', 'price' => '20.00']);
        $list = $this->createList($buyer, $organization, $location, 'Produce');
        $base = "/api/v1/organizations/{$organization->id}/saved-lists/{$list['id']}";
        $added = $this->asUser($buyer)->postJson("{$base}/items", [
            'canonical_product_id' => $product->id,
            'selected_offer_id' => $first->id,
            'quantity_target' => '2.5',
            'preferred_unit' => 'lb',
            'notes' => '<script>private note</script>',
            'expected_list_version' => 1,
        ])->assertCreated()->json();
        $item = $added['item'];
        $this->assertSame($product->id, $item['canonical_product']['id']);
        $this->assertSame($first->id, $item['selected_offer']['id']);
        $this->assertSame($second->id, $item['best_normalized_option']['id']);
        $this->assertSame($second->id, $item['best_available_option']['id']);

        $changed = $this->asUser($buyer)->patchJson("{$base}/items/{$item['id']}", [
            'quantity_target' => '3.25',
            'notes' => 'Use ripe tomatoes',
            'expected_version' => $item['version'],
        ])->assertOk()->json('item');
        $cleared = $this->asUser($buyer)->putJson("{$base}/items/{$item['id']}/selected-offer", [
            'selected_offer_id' => null,
            'expected_version' => $changed['version'],
        ])->assertOk()->json('item');
        $this->assertNull($cleared['selected_offer_id']);
        $this->assertSame('3.250000', $cleared['quantity_target']);

        $this->asUser($buyer)->postJson("{$base}/items", [
            'canonical_product_id' => $product->id,
            'expected_list_version' => 4,
        ])->assertConflict()->assertJsonPath('error.code', 'duplicate_list_item');
        $secondProduct = CanonicalProduct::factory()->create(['category_id' => $product->category_id]);
        $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Second item supplier'), $secondProduct, ['sku' => 'LIST-SECOND']);
        $secondItem = $this->asUser($buyer)->postJson("{$base}/items", [
            'canonical_product_id' => $secondProduct->id,
            'expected_list_version' => 4,
        ])->assertCreated()->json('item');
        $this->asUser($buyer)->putJson("{$base}/reorder", [
            'item_ids' => [$secondItem['id'], $item['id']],
            'expected_list_version' => 5,
        ])->assertOk()
            ->assertJsonPath('saved_list.version', 6)
            ->assertJsonPath('items.0.id', $secondItem['id'])
            ->assertJsonPath('items.0.sort_order', 1)
            ->assertJsonPath('items.1.id', $item['id'])
            ->assertJsonPath('items.1.sort_order', 2);
        $this->assertDatabaseHas('saved_list_items', ['id' => $item['id'], 'notes' => 'Use ripe tomatoes', 'sort_order' => 2]);
    }

    public function test_selected_offer_becoming_unavailable_keeps_item_and_alternatives_visible(): void
    {
        [$organization, $location, $product] = $this->context(true);
        $buyer = $this->buyer($organization);
        $selected = $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Selected'), $product, ['sku' => 'UNAVAILABLE-SELECTED']);
        $alternative = $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Alternative'), $product, ['sku' => 'UNAVAILABLE-ALTERNATIVE', 'price' => '19.00']);
        $list = $this->createList($buyer, $organization, $location, 'Availability');
        $base = "/api/v1/organizations/{$organization->id}/saved-lists/{$list['id']}";
        $item = $this->asUser($buyer)->postJson("{$base}/items", [
            'canonical_product_id' => $product->id,
            'selected_offer_id' => $selected->id,
            'expected_list_version' => 1,
        ])->assertCreated()->json('item');

        // Supplier offers are mutable projections. The list selection itself is
        // deliberately not changed when an offer is later deactivated.
        $selected->update(['is_active' => false]);
        $this->asUser($buyer)->getJson($base)
            ->assertOk()
            ->assertJsonPath('saved_list.items.0.id', $item['id'])
            ->assertJsonPath('saved_list.items.0.selected_offer_id', $selected->id)
            ->assertJsonPath('saved_list.items.0.selected_offer', null)
            ->assertJsonPath('saved_list.items.0.selected_offer_unavailable', true)
            ->assertJsonPath('saved_list.items.0.best_available_option.id', $alternative->id)
            ->assertJsonFragment(['selected_offer_unavailable']);
    }

    public function test_current_price_updates_appear_without_rewriting_saved_intent(): void
    {
        [$organization, $location, $product] = $this->context(true);
        $buyer = $this->buyer($organization);
        $offer = $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Changing price'), $product, ['sku' => 'PRICE-UPDATE', 'price' => '24.00']);
        $list = $this->createList($buyer, $organization, $location, 'Price-aware intent');
        $base = "/api/v1/organizations/{$organization->id}/saved-lists/{$list['id']}";
        $item = $this->asUser($buyer)->postJson("{$base}/items", [
            'canonical_product_id' => $product->id,
            'selected_offer_id' => $offer->id,
            'quantity_target' => '4',
            'notes' => 'Use on Friday.',
            'expected_list_version' => 1,
        ])->assertCreated()->json('item');

        $replacement = $this->newerObservation($offer, $buyer, '18.00');
        $offer->update(['current_price_observation_id' => $replacement->id]);

        $this->asUser($buyer)->getJson($base)
            ->assertOk()
            ->assertJsonPath('saved_list.items.0.id', $item['id'])
            ->assertJsonPath('saved_list.items.0.quantity_target', '4.000000')
            ->assertJsonPath('saved_list.items.0.notes', 'Use on Friday.')
            ->assertJsonPath('saved_list.items.0.selected_offer.case_price', '18');
        $this->assertDatabaseHas('saved_list_items', ['id' => $item['id'], 'selected_offer_id' => $offer->id]);
    }

    public function test_variant_intent_uses_only_that_variants_current_offers_and_allows_a_distinct_canonical_item(): void
    {
        [$organization, $location, $product] = $this->context(true);
        $buyer = $this->buyer($organization);
        $generic = $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Generic'), $product, ['sku' => 'GENERIC-VARIANT']);
        $variant = ProductVariant::factory()->for($product)->create(['code' => 'vine-ripe']);
        $variantOffer = $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Vine ripe'), $product, ['sku' => 'VINE-RIPE']);
        $variantOffer->vendorItem->currentMapping->update(['product_variant_id' => $variant->id]);
        $list = $this->createList($buyer, $organization, $location, 'Variant intent');
        $base = "/api/v1/organizations/{$organization->id}/saved-lists/{$list['id']}";

        $this->asUser($buyer)->postJson("{$base}/items", [
            'canonical_product_id' => $product->id,
            'expected_list_version' => 1,
        ])->assertCreated();
        $variantItem = $this->asUser($buyer)->postJson("{$base}/items", [
            'canonical_product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'selected_offer_id' => $variantOffer->id,
            'expected_list_version' => 2,
        ])->assertCreated()->json('item');

        $this->assertSame($variant->id, $variantItem['product_variant']['id']);
        $this->assertSame([$variantOffer->id], array_column($variantItem['offer_choices'], 'id'));
        $this->assertNotSame($generic->id, $variantItem['offer_choices'][0]['id']);
        $this->asUser($buyer)->postJson("{$base}/items", [
            'canonical_product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'expected_list_version' => 3,
        ])->assertConflict()->assertJsonPath('error.code', 'duplicate_list_item');
    }

    public function test_tenant_location_offer_and_direct_item_tampering_are_rejected(): void
    {
        [$organization, $location, $product] = $this->context(true);
        $buyer = $this->buyer($organization);
        $list = $this->createList($buyer, $organization, $location, 'Tenant A');
        $base = "/api/v1/organizations/{$organization->id}/saved-lists/{$list['id']}";
        $item = $this->asUser($buyer)->postJson("{$base}/items", [
            'canonical_product_id' => $product->id,
            'expected_list_version' => 1,
        ])->assertCreated()->json('item');
        $otherOrganization = Organization::factory()->create();
        $otherLocation = RestaurantLocation::factory()->for($otherOrganization)->create();
        $foreignOffer = $this->comparisonOffer($this->comparisonAccount($otherOrganization, $otherLocation, 'Foreign'), $product, ['sku' => 'FOREIGN-OFFER']);
        $otherBuyer = $this->buyer($otherOrganization);
        $otherList = $this->createList($otherBuyer, $otherOrganization, $otherLocation, 'Tenant B');

        $this->asUser($buyer)->getJson("/api/v1/organizations/{$organization->id}/saved-lists/{$otherList['id']}")
            ->assertNotFound();
        $this->asUser($buyer)->patchJson("{$base}/items/{$otherList['id']}", ['notes' => 'tamper', 'expected_version' => 1])
            ->assertNotFound();
        $this->asUser($buyer)->putJson("{$base}/items/{$item['id']}/selected-offer", [
            'selected_offer_id' => $foreignOffer->id,
            'expected_version' => $item['version'],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_selected_offer');
        $this->asUser($buyer)->postJson("/api/v1/organizations/{$organization->id}/saved-lists", [
            'name' => 'Forged',
            'location_id' => $location->id,
            'organization_id' => $otherOrganization->id,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_tenant_context');
    }

    public function test_stale_versions_return_structured_conflicts_for_item_and_reorder_updates(): void
    {
        [$organization, $location, $product] = $this->context(true);
        $buyer = $this->buyer($organization);
        $list = $this->createList($buyer, $organization, $location, 'Concurrent');
        $base = "/api/v1/organizations/{$organization->id}/saved-lists/{$list['id']}";
        $item = $this->asUser($buyer)->postJson("{$base}/items", [
            'canonical_product_id' => $product->id,
            'expected_list_version' => 1,
        ])->assertCreated()->json('item');
        $this->asUser($buyer)->patchJson("{$base}/items/{$item['id']}", ['notes' => 'first', 'expected_version' => $item['version']])
            ->assertOk();
        $this->asUser($buyer)->patchJson("{$base}/items/{$item['id']}", ['notes' => 'stale', 'expected_version' => $item['version']])
            ->assertConflict()->assertJsonPath('error.code', 'conflict')->assertJsonPath('error.details.resource', 'saved_list_item');
        $this->asUser($buyer)->putJson("{$base}/reorder", [
            'item_ids' => [$item['id']],
            'expected_list_version' => 1,
        ])->assertConflict()->assertJsonPath('error.details.resource', 'saved_list');
    }

    public function test_detail_uses_batched_offer_summaries_for_a_representative_list(): void
    {
        [$organization, $location, $seededProduct] = $this->context(true);
        $buyer = $this->buyer($organization);
        $list = SavedList::query()->create([
            'organization_id' => $organization->id,
            'restaurant_location_id' => $location->id,
            'name' => 'Representative list',
            'status' => 'active',
            'version' => 1,
            'created_by' => $buyer->id,
            'updated_by' => $buyer->id,
        ]);
        foreach (range(1, 8) as $number) {
            $product = $number === 1 ? $seededProduct : CanonicalProduct::factory()->create(['category_id' => $seededProduct->category_id]);
            $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Batch '.$number), $product, ['sku' => 'BATCH-'.$number]);
            SavedListItem::query()->create([
                'saved_list_id' => $list->id,
                'organization_id' => $organization->id,
                'restaurant_location_id' => $location->id,
                'canonical_product_id' => $product->id,
                'quantity_target' => '1',
                'sort_order' => $number,
                'version' => 1,
                'created_by' => $buyer->id,
                'updated_by' => $buyer->id,
            ]);
        }
        $context = new TenantContext($buyer, $organization, $buyer->memberships()->where('organization_id', $organization->id)->sole());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $detail = app(SavedListService::class)->detail($context, $list->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(8, $detail['saved_list']['items']);
        $this->assertLessThanOrEqual(11, count($queries), 'List detail must batch offer summaries rather than query per item.');
    }

    /** @return array{Organization, RestaurantLocation}|array{Organization, RestaurantLocation, CanonicalProduct} */
    private function context(bool $withProduct = false): array
    {
        $organization = Organization::factory()->create();
        $location = RestaurantLocation::factory()->for($organization)->create();
        if (! $withProduct) {
            return [$organization, $location];
        }

        return [$organization, $location, CanonicalProduct::query()->with('category')->where('normalized_key', 'beefsteak tomato')->sole()];
    }

    private function buyer(Organization $organization): User
    {
        $buyer = User::factory()->create();
        $this->membership($buyer, $organization, Role::Buyer);

        return $buyer;
    }

    /** @return array<string, mixed> */
    private function createList(User $buyer, Organization $organization, RestaurantLocation $location, string $name): array
    {
        return $this->asUser($buyer)
            ->postJson("/api/v1/organizations/{$organization->id}/saved-lists", ['name' => $name, 'location_id' => $location->id])
            ->assertCreated()
            ->json('saved_list');
    }

    private function newerObservation(SupplierOffer $offer, User $actor, string $casePrice): PriceObservation
    {
        $current = $offer->currentPriceObservation;
        $run = ImportRun::query()->create([
            'organization_id' => $offer->organization_id,
            'restaurant_location_id' => $offer->restaurant_location_id,
            'supplier_account_id' => $offer->supplier_account_id,
            'created_by' => $actor->id,
            'fingerprint' => hash('sha256', 'saved-list-price-update-'.Str::uuid7()),
            'request_id' => 'saved-list-price-update-'.Str::uuid7(),
        ]);
        $file = ImportFile::query()->create([
            'organization_id' => $offer->organization_id,
            'import_run_id' => $run->id,
            'original_filename' => 'price-update.csv',
            'storage_key' => 'saved-list-price-update/'.Str::uuid7(),
            'size_bytes' => 1,
            'media_type' => 'text/csv',
            'format' => 'csv',
        ]);
        $raw = RawImportRecord::query()->create([
            'organization_id' => $offer->organization_id,
            'import_run_id' => $run->id,
            'import_file_id' => $file->id,
            'source_row' => 2,
            'raw_values' => ['SKU' => 'PRICE-UPDATE', 'Description' => 'Updated price evidence'],
            'mapped_values' => ['price' => $casePrice, 'currency' => $current->currency, 'observed_at' => now()->addMinute()->toIso8601String()],
            'status' => 'normalized',
        ]);
        $normalized = NormalizedImportRecord::query()->create([
            'organization_id' => $offer->organization_id,
            'import_run_id' => $run->id,
            'raw_import_record_id' => $raw->id,
            'parser_version' => '1.0.0',
            'normalized_values' => ['packs' => [], 'measurements' => [], 'case_quantity' => null, 'size_code' => '5X6', 'variable_weight' => false],
            'warnings' => [],
            'confidence' => 'high',
        ]);

        return PriceObservation::query()->create([
            'organization_id' => $offer->organization_id,
            'supplier_offer_id' => $offer->id,
            'import_run_id' => $run->id,
            'normalized_import_record_id' => $normalized->id,
            'case_price' => $casePrice,
            'currency' => $current->currency,
            'source_price_evidence' => ['source_price' => $casePrice, 'source_currency' => $current->currency],
            'source_pack_evidence' => $current->source_pack_evidence,
            'normalized_quantity' => $current->normalized_quantity,
            'normalized_unit' => $current->normalized_unit,
            'dimension' => $current->dimension,
            'availability' => $current->availability,
            'source_updated_at' => $current->source_updated_at,
            'observed_at' => now()->addMinute(),
            'imported_at' => now(),
            'formula_version' => $current->formula_version,
            'match_evidence' => $current->match_evidence,
        ]);
    }
}
