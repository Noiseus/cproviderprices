<?php

namespace Tests\Feature;

use App\Models\CanonicalProduct;
use App\Models\Organization;
use App\Models\RestaurantLocation;
use App\Services\PriceHistoryService;
use App\Services\SearchComparisonCache;
use Database\Seeders\CatalogTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsComparisonFixtures;
use Tests\TestCase;

/** Generous local smoke guard, not a production microbenchmark. */
final class PriceHistoryPerformanceTest extends TestCase
{
    use BuildsComparisonFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogTaxonomySeeder::class);
    }

    public function test_pilot_history_query_count_cache_scope_and_latency_remain_bounded(): void
    {
        $organization = Organization::factory()->create();
        $location = RestaurantLocation::factory()->for($organization)->create();
        $product = CanonicalProduct::query()->with('category')->where('normalized_key', 'beefsteak tomato')->sole();
        $account = $this->comparisonAccount($organization, $location, 'History performance supplier');
        foreach (range(1, 24) as $number) {
            $this->comparisonOffer($account, $product, [
                'sku' => 'PERF-'.$number,
                'price' => (string) (20 + $number),
                'observed_at' => now()->subDays($number % 20),
            ]);
        }
        $filters = ['from' => now()->subDays(30)->startOfDay()->toImmutable(), 'to' => now()->endOfDay()->toImmutable()];
        $history = app(PriceHistoryService::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $payload = $history->history($organization->id, $location, $product, $filters);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(24, $payload['observations']);
        $this->assertLessThanOrEqual(6, count($queries));

        $samples = [];
        foreach (range(1, 10) as $_) {
            $started = hrtime(true);
            $history->history($organization->id, $location, $product, $filters);
            $samples[] = (hrtime(true) - $started) / 1_000_000;
        }
        $p95 = $this->p95($samples);
        fwrite(STDERR, sprintf("Prompt 16 local history pilot (ms): uncached p95=%.3f, query_count=%d\n", $p95, count($queries)));
        $this->assertLessThan(1500, $p95);

        Cache::flush();
        $cache = app(SearchComparisonCache::class);
        $resolutions = 0;
        $parameters = ['from' => $filters['from']->toDateString(), 'to' => $filters['to']->toDateString()];
        $cache->rememberHistory($organization->id, $location->id, $product->id, $parameters, function () use (&$resolutions, $history, $organization, $location, $product, $filters): array {
            $resolutions++;

            return $history->history($organization->id, $location, $product, $filters);
        });
        $cache->rememberHistory($organization->id, $location->id, $product->id, $parameters, function () use (&$resolutions): array {
            $resolutions++;

            return [];
        });
        $otherOrganization = Organization::factory()->create();
        $otherLocation = RestaurantLocation::factory()->for($otherOrganization)->create();
        $cache->rememberHistory($otherOrganization->id, $otherLocation->id, $product->id, $parameters, function () use (&$resolutions): array {
            $resolutions++;

            return [];
        });
        $this->assertSame(2, $resolutions, 'History cache keys must include organization and location.');
    }

    /** @param list<float> $samples */
    private function p95(array $samples): float
    {
        sort($samples, SORT_NUMERIC);

        return $samples[(int) ceil(count($samples) * 0.95) - 1];
    }
}
