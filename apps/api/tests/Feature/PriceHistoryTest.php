<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Enums\SupplierRefreshCadence;
use App\Enums\SupplierSourceMethod;
use App\Events\ImportPriceObservationsCompleted;
use App\Jobs\RecalculatePriceFreshness;
use App\Models\CanonicalProduct;
use App\Models\Organization;
use App\Models\RestaurantLocation;
use App\Models\User;
use App\Services\PriceFreshnessService;
use App\Services\PriceHistoryService;
use App\Services\SearchComparisonCache;
use Database\Seeders\CatalogTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsComparisonFixtures;
use Tests\Concerns\BuildsTenantContext;
use Tests\TestCase;

final class PriceHistoryTest extends TestCase
{
    use BuildsComparisonFixtures;
    use BuildsTenantContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogTaxonomySeeder::class);
    }

    public function test_history_retains_immutable_source_and_import_times_and_aggregates_only_compatible_units(): void
    {
        [$organization, $location, $product] = $this->context();
        $account = $this->comparisonAccount($organization, $location, 'History supplier');
        $sourceUpdated = now()->subHours(3)->toImmutable();
        $imported = now()->subHours(2)->toImmutable();
        $this->comparisonOffer($account, $product, [
            'sku' => 'HISTORY-ONE', 'price' => '10.00', 'quantity' => '453.59237',
            'observed_at' => now()->subHour(), 'source_updated_at' => $sourceUpdated, 'imported_at' => $imported,
        ]);
        $this->comparisonOffer($account, $product, [
            'sku' => 'HISTORY-TWO', 'price' => '20.00', 'quantity' => '453.59237',
            'observed_at' => now()->subMinutes(30),
        ]);
        $this->comparisonOffer($account, $product, [
            'sku' => 'HISTORY-VARIABLE', 'price' => '30.00', 'quantity' => null,
            'variable_weight' => true, 'observed_at' => now()->subMinutes(15),
        ]);
        $this->comparisonOffer($account, $product, [
            'sku' => 'HISTORY-VOLUME', 'price' => '12.00', 'quantity' => '3785.411784',
            'dimension' => 'volume', 'normalized_unit' => 'mL', 'observed_at' => now()->subMinutes(10),
        ]);

        $payload = app(PriceHistoryService::class)->history($organization->id, $location, $product, [
            'from' => now()->subDay()->startOfDay()->toImmutable(),
            'to' => now()->endOfDay()->toImmutable(),
        ]);

        $this->assertSame('isolated_incompatible_units', $payload['aggregation']['state']);
        $this->assertSame(1, $payload['aggregation']['excluded_unsafe_count']);
        $weight = collect($this->rows($payload, 'series'))->firstWhere('dimension', 'weight');
        $this->assertNotNull($weight);
        $this->assertSame('15', $weight['normalized_price']);
        $this->assertSame(2, $weight['observation_count']);
        $first = collect($this->rows($payload, 'observations'))->firstWhere('source_updated_at', $sourceUpdated->toIso8601String());
        $this->assertSame($imported->toIso8601String(), $first['imported_at']);
        $this->assertSame('manual_import', $first['freshness']);
        $this->assertSame($product->id, $first['match']['canonical_product_id']);
        $unsafe = collect($this->rows($payload, 'observations'))->firstWhere('aggregation_state', 'unsafe_variable_weight');
        $this->assertNotNull($unsafe);
        $this->assertSame('unsafe_variable_weight', $unsafe['aggregation_state']);
    }

    public function test_freshness_boundaries_connection_failure_and_manual_import_are_deterministic(): void
    {
        [$organization, $location, $product] = $this->context();
        $automatic = $this->comparisonAccount($organization, $location, 'Automatic daily', SupplierRefreshCadence::Daily);
        $automatic->update(['source_method' => SupplierSourceMethod::FutureAutomaticSync]);
        $fresh = $this->comparisonOffer($automatic, $product, ['sku' => 'FRESH', 'observed_at' => now()->subHours(23)]);
        $aging = $this->comparisonOffer($automatic, $product, ['sku' => 'AGING', 'observed_at' => now()->subHours(25)]);
        $stale = $this->comparisonOffer($automatic, $product, ['sku' => 'STALE', 'observed_at' => now()->subHours(49)]);
        $manual = $this->comparisonAccount($organization, $location, 'Manual daily', SupplierRefreshCadence::Daily);
        $manualOffer = $this->comparisonOffer($manual, $product, ['sku' => 'MANUAL', 'observed_at' => now()->subDays(10)]);

        $payload = app(PriceHistoryService::class)->history($organization->id, $location, $product, [
            'from' => now()->subDays(11)->startOfDay()->toImmutable(),
            'to' => now()->endOfDay()->toImmutable(),
        ]);
        $byOffer = collect($this->rows($payload, 'observations'))->keyBy('id');
        $this->assertSame('fresh', $byOffer[$fresh->current_price_observation_id]['freshness']);
        $this->assertSame('aging', $byOffer[$aging->current_price_observation_id]['freshness']);
        $this->assertSame('stale', $byOffer[$stale->current_price_observation_id]['freshness']);
        $this->assertSame('manual_import', $byOffer[$manualOffer->current_price_observation_id]['freshness']);

        $automatic->update(['last_sync_failed_at' => now(), 'last_sync_failure_code' => 'connection_unavailable']);
        $afterFailure = app(PriceHistoryService::class)->history($organization->id, $location, $product, [
            'from' => now()->subDays(11)->startOfDay()->toImmutable(),
            'to' => now()->endOfDay()->toImmutable(),
        ]);
        $this->assertSame('connection_failed', collect($this->rows($afterFailure, 'observations'))->keyBy('id')[$fresh->current_price_observation_id]['freshness']);
        $this->assertDatabaseHas('price_observations', ['id' => $fresh->current_price_observation_id, 'case_price' => '24.500000']);
    }

    public function test_history_endpoint_scopes_organization_location_date_range_and_supplier_filter(): void
    {
        [$organization, $location, $product] = $this->context();
        $visibleAccount = $this->comparisonAccount($organization, $location, 'Visible history');
        $visible = $this->comparisonOffer($visibleAccount, $product, ['sku' => 'VISIBLE-HISTORY']);
        $otherLocation = RestaurantLocation::factory()->for($organization)->create();
        $hiddenLocation = $this->comparisonOffer($this->comparisonAccount($organization, $otherLocation, 'Other location history'), $product, ['sku' => 'HIDDEN-LOCATION']);
        $otherOrganization = Organization::factory()->create();
        $otherTenantLocation = RestaurantLocation::factory()->for($otherOrganization)->create();
        $hiddenTenant = $this->comparisonOffer($this->comparisonAccount($otherOrganization, $otherTenantLocation, 'Other tenant history'), $product, ['sku' => 'HIDDEN-TENANT']);
        $user = User::factory()->create();
        $this->membership($user, $organization);
        $path = "/api/v1/organizations/{$organization->id}/price-history/{$product->id}?location_id={$location->id}&from=".now()->subDay()->toDateString().'&to='.now()->toDateString()."&supplier_id[]={$visibleAccount->id}";

        $response = $this->asUser($user)->getJson($path)
            ->assertOk()
            ->assertJsonPath('observations.0.id', $visible->current_price_observation_id)
            ->assertJsonMissing(['id' => $hiddenLocation->current_price_observation_id])
            ->assertJsonMissing(['id' => $hiddenTenant->current_price_observation_id]);
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->getJson("/api/v1/organizations/{$organization->id}/price-history/{$product->id}?location_id={$otherTenantLocation->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_import_completion_event_and_scheduled_recalculation_invalidate_only_the_affected_cache_scope(): void
    {
        [$organization, $location, $product] = $this->context();
        $account = $this->comparisonAccount($organization, $location, 'Cache history');
        $offer = $this->comparisonOffer($account, $product, ['sku' => 'CACHE-HISTORY']);
        $run = $offer->currentPriceObservation->importRun;
        $run->update(['status' => ImportStatus::Completed, 'finished_at' => now()]);
        $cache = app(SearchComparisonCache::class);
        $resolutions = 0;
        $cache->rememberHistory($organization->id, $location->id, $product->id, ['from' => '2026-01-01'], function () use (&$resolutions): array {
            $resolutions++;

            return ['observations' => []];
        });
        event(new ImportPriceObservationsCompleted($organization->id, $location->id, $run->id));
        $cache->rememberHistory($organization->id, $location->id, $product->id, ['from' => '2026-01-01'], function () use (&$resolutions): array {
            $resolutions++;

            return ['observations' => []];
        });
        $this->assertSame(2, $resolutions);
        $account->update(['source_method' => SupplierSourceMethod::FutureAutomaticSync, 'last_sync_failed_at' => now()]);
        $offer->forceFill(['freshness_calculated_at' => null])->save();
        (new RecalculatePriceFreshness)->handle(app(PriceFreshnessService::class), $cache);
        $this->assertDatabaseHas('supplier_offers', ['id' => $offer->id, 'freshness_state' => 'connection_failed']);
    }

    /** @return array{Organization, RestaurantLocation, CanonicalProduct} */
    private function context(): array
    {
        $organization = Organization::factory()->create();
        $location = RestaurantLocation::factory()->for($organization)->create();
        $product = CanonicalProduct::query()->with('category')->where('normalized_key', 'beefsteak tomato')->sole();

        return [$organization, $location, $product];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function rows(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];
        if (! is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
