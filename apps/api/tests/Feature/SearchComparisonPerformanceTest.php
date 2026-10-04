<?php

namespace Tests\Feature;

use App\Enums\MatchClass;
use App\Enums\OfferAvailability;
use App\Models\CanonicalProduct;
use App\Models\Organization;
use App\Models\RestaurantLocation;
use App\Services\OfferComparisonService;
use App\Services\ProductSearchService;
use App\Services\SearchComparisonCache;
use Database\Seeders\CatalogTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsComparisonFixtures;
use Tests\TestCase;

/** Explicit local pilot-scale performance guard; run on demand, not per edit. */
final class SearchComparisonPerformanceTest extends TestCase
{
    use BuildsComparisonFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogTaxonomySeeder::class);
    }

    public function test_pilot_search_cache_and_first_comparison_are_within_local_targets(): void
    {
        $organization = Organization::factory()->create();
        $location = RestaurantLocation::factory()->for($organization)->create();
        $beefsteak = CanonicalProduct::query()->where('normalized_key', 'beefsteak tomato')->sole();
        $this->pilotCatalog($beefsteak->category_id);
        $this->beefsteakOffers($organization, $location, $beefsteak);
        $search = app(ProductSearchService::class);
        $cache = app(SearchComparisonCache::class);
        $comparison = app(OfferComparisonService::class);
        $queries = ['tomato', 'tomate', 'beef tomato', 'beef tomato 5x6 25 lb'];
        $cold = [];
        $warm = [];
        Cache::flush();
        foreach (range(1, 10) as $_) {
            foreach ($queries as $query) {
                $filters = ['query' => $query];
                $cache->invalidate($organization->id, $location->id);
                $started = hrtime(true);
                $payload = $cache->rememberSearch($organization->id, $location->id, $filters, fn (): array => $search->search($organization->id, $location->id, $filters));
                $cold[] = $this->milliseconds($started);
                $this->assertNotEmpty($payload['results']);

                $started = hrtime(true);
                $cache->rememberSearch($organization->id, $location->id, $filters, fn (): array => $search->search($organization->id, $location->id, $filters));
                $warm[] = $this->milliseconds($started);
            }
        }
        $comparisonTimes = [];
        foreach (range(1, 20) as $_) {
            $started = hrtime(true);
            $payload = $comparison->compare($organization->id, $location, $beefsteak, []);
            $comparisonTimes[] = $this->milliseconds($started);
            $this->assertCount(6, $payload['offers']);
        }
        $coldP95 = $this->p95($cold);
        $warmP95 = $this->p95($warm);
        $comparisonP95 = $this->p95($comparisonTimes);
        fwrite(STDERR, sprintf("Prompt 14/15 local pilot metrics (ms): uncached p95=%.3f, cached p95=%.3f, comparison p95=%.3f\n", $coldP95, $warmP95, $comparisonP95));

        $this->assertLessThan(1500, $coldP95);
        $this->assertLessThan(800, $warmP95);
        $this->assertLessThan(1200, $comparisonP95);
    }

    private function pilotCatalog(string $categoryId): void
    {
        $products = [];
        $aliases = [];
        $variants = [];
        $now = now();
        foreach (range(1, 5000) as $number) {
            $id = (string) Str::uuid7();
            $products[] = ['id' => $id, 'category_id' => $categoryId, 'english_name' => 'Pilot Tomato '.$number, 'normalized_key' => 'pilot tomato '.$number, 'attributes' => json_encode(['family' => 'tomato'], JSON_THROW_ON_ERROR), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
            $aliases[] = ['id' => (string) Str::uuid7(), 'canonical_product_id' => $id, 'product_variant_id' => null, 'locale' => 'es', 'alias' => 'Tomate piloto '.$number, 'normalized_alias' => 'tomate piloto '.$number, 'created_at' => $now, 'updated_at' => $now];
            if ($number <= 2500) {
                $variants[] = ['id' => (string) Str::uuid7(), 'canonical_product_id' => $id, 'code' => 'PILOT-'.$number, 'normalized_key' => 'pilot tomato variant '.$number, 'attributes' => json_encode(['size_code' => '5X6'], JSON_THROW_ON_ERROR), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($products, 500) as $chunk) {
            DB::table('canonical_products')->insert($chunk);
        }
        foreach (array_chunk($aliases, 500) as $chunk) {
            DB::table('product_aliases')->insert($chunk);
        }
        foreach (array_chunk($variants, 500) as $chunk) {
            DB::table('product_variants')->insert($chunk);
        }
    }

    private function beefsteakOffers(Organization $organization, RestaurantLocation $location, CanonicalProduct $product): void
    {
        $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Lowest case'), $product, ['price' => '18.00', 'quantity' => '4535.9237', 'availability' => OfferAvailability::Unavailable, 'match_class' => MatchClass::ExactMatch]);
        $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Normalized winner'), $product, ['price' => '30.00', 'quantity' => '22679.6185', 'availability' => OfferAvailability::Unavailable]);
        $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Available winner'), $product, ['price' => '21.00', 'quantity' => '13607.7711', 'availability' => OfferAvailability::Available]);
        $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Alternative'), $product, ['price' => '10.00', 'quantity' => '11339.80925', 'match_class' => MatchClass::ComparableAlternative]);
        $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Unsafe'), $product, ['price' => '15.00', 'quantity' => null]);
        $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Stale'), $product, ['price' => '22.00', 'quantity' => '11339.80925', 'observed_at' => now()->subDays(15)]);
    }

    /** @param list<float> $samples */
    private function p95(array $samples): float
    {
        sort($samples, SORT_NUMERIC);

        return $samples[(int) ceil(count($samples) * 0.95) - 1];
    }

    private function milliseconds(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}
