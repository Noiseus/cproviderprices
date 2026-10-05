<?php

namespace Tests\Feature;

use App\Enums\MatchClass;
use App\Enums\OfferAvailability;
use App\Models\CanonicalProduct;
use App\Models\Organization;
use App\Models\RestaurantLocation;
use App\Models\User;
use App\Services\ProductSearchService;
use Database\Seeders\CatalogTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsComparisonFixtures;
use Tests\Concerns\BuildsTenantContext;
use Tests\TestCase;

final class ProductSearchTest extends TestCase
{
    use BuildsComparisonFixtures;
    use BuildsTenantContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogTaxonomySeeder::class);
    }

    public function test_bilingual_and_structured_queries_rank_beefsteak_deterministically(): void
    {
        [$organization, $location, $beefsteak] = $this->context();
        $account = $this->comparisonAccount($organization, $location, 'Tomato supplier');
        $this->comparisonOffer($account, $beefsteak, ['sku' => 'BEEF-25', 'description' => 'TOM BEEF 5X6 1/25 LB CS']);
        $service = app(ProductSearchService::class);

        $tomato = $service->search($organization->id, $location->id, ['query' => 'tomato']);
        $spanish = $service->search($organization->id, $location->id, ['query' => 'tomate']);
        $beef = $service->search($organization->id, $location->id, ['query' => 'beef tomato']);
        $structured = $service->search($organization->id, $location->id, ['query' => 'beef tomato 5x6 25 lb']);
        $typo = $service->search($organization->id, $location->id, ['query' => 'tomto']);

        $this->assertGreaterThanOrEqual(2, $tomato['pagination']['total']);
        $this->assertGreaterThanOrEqual(2, $spanish['pagination']['total']);
        $this->assertSame($beefsteak->id, $beef['results'][0]['canonical_product']['id']);
        $this->assertSame($beefsteak->id, $structured['results'][0]['canonical_product']['id']);
        $this->assertSame(1, $structured['results'][0]['offer_count']);
        $this->assertSame('0.98', $structured['results'][0]['price_summary']['lowest_normalized_price']);
        $this->assertGreaterThanOrEqual(1, $typo['pagination']['total']);
    }

    public function test_search_filters_sorts_and_pagination_are_server_authoritative_and_stable(): void
    {
        [$organization, $location, $beefsteak] = $this->context();
        $tomato = CanonicalProduct::query()->where('normalized_key', 'tomato')->sole();
        $roma = CanonicalProduct::query()->where('normalized_key', 'roma tomato')->sole();
        $beefAccount = $this->comparisonAccount($organization, $location, 'Acme produce');
        $tomatoAccount = $this->comparisonAccount($organization, $location, 'Plain tomato supplier');
        $romaAccount = $this->comparisonAccount($organization, $location, 'Roma supplier');
        $this->comparisonOffer($beefAccount, $beefsteak, [
            'description' => 'ACME BEEF TOMATO 5X6 1/25 LB CS',
            'brand' => 'Acme Farms',
            'price' => '30.00',
            'quantity' => '22679.6185',
            'match_class' => MatchClass::ExactMatch,
            'observed_at' => now()->subHours(3),
        ]);
        $this->comparisonOffer($tomatoAccount, $tomato, [
            'description' => 'TOMATO 1/10 LB CASE',
            'price' => '5.00',
            'quantity' => '4535.9237',
            'availability' => OfferAvailability::Unavailable,
            'observed_at' => now()->subHours(2),
        ]);
        $this->comparisonOffer($romaAccount, $roma, [
            'description' => 'ROMA TOMATO 1/20 LB CASE',
            'brand' => 'Roma Farms',
            'price' => '20.00',
            'quantity' => '9071.8474',
            'observed_at' => now(),
        ]);
        $service = app(ProductSearchService::class);

        $filtered = $service->search($organization->id, $location->id, [
            'query' => 'acme beef tomato',
            'category_id' => $beefsteak->category_id,
            'supplier_ids' => [$beefAccount->id],
            'brands' => ['acme farms'],
            'units' => ['weight'],
            'availability' => ['available'],
            'freshness' => ['manual_import'],
            'match_classes' => ['exact'],
        ]);
        $byCase = $service->search($organization->id, $location->id, ['query' => 'tomato', 'sort' => 'lowest_case_price']);
        $byNormalized = $service->search($organization->id, $location->id, ['query' => 'tomato', 'sort' => 'lowest_normalized_price']);
        $byNewest = $service->search($organization->id, $location->id, ['query' => 'tomato', 'sort' => 'newest_observation']);
        $firstPage = $service->search($organization->id, $location->id, ['query' => 'tomato', 'per_page' => 1, 'page' => 1]);
        $secondPage = $service->search($organization->id, $location->id, ['query' => 'tomato', 'per_page' => 1, 'page' => 2]);

        $this->assertSame($beefsteak->id, $filtered['results'][0]['canonical_product']['id']);
        $this->assertSame(1, $filtered['results'][0]['offer_count']);
        $this->assertSame($tomato->id, $byCase['results'][0]['canonical_product']['id']);
        $this->assertSame($tomato->id, $byNormalized['results'][0]['canonical_product']['id']);
        $this->assertSame($roma->id, $byNewest['results'][0]['canonical_product']['id']);
        $this->assertSame(1, $firstPage['pagination']['per_page']);
        $this->assertSame(2, $secondPage['pagination']['page']);
        $this->assertNotSame($firstPage['results'][0]['canonical_product']['id'], $secondPage['results'][0]['canonical_product']['id']);
    }

    public function test_search_api_never_uses_forged_location_or_foreign_private_offers(): void
    {
        [$organization, $location, $beefsteak] = $this->context();
        $visible = $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Visible supplier'), $beefsteak);
        $otherOrganization = Organization::factory()->create();
        $otherLocation = RestaurantLocation::factory()->for($otherOrganization)->create();
        $hidden = $this->comparisonOffer($this->comparisonAccount($otherOrganization, $otherLocation, 'Hidden supplier'), $beefsteak);
        $user = User::factory()->create();
        $this->membership($user, $organization);

        $response = $this->asUser($user)->getJson("/api/v1/organizations/{$organization->id}/product-search?q=beef%20tomato&location_id={$location->id}");
        $response->assertOk()
            ->assertJsonPath('results.0.canonical_product.id', $beefsteak->id)
            ->assertJsonPath('results.0.offer_count', 1)
            ->assertJsonPath('results.0.suppliers.0.id', $visible->supplier_account_id)
            ->assertJsonMissing(['id' => $hidden->supplier_account_id]);
        $this->getJson("/api/v1/organizations/{$organization->id}/product-search?q=beef%20tomato&location_id={$otherLocation->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    /** @return array{Organization, RestaurantLocation, CanonicalProduct} */
    private function context(): array
    {
        $organization = Organization::factory()->create();
        $location = RestaurantLocation::factory()->for($organization)->create();
        $beefsteak = CanonicalProduct::query()->where('normalized_key', 'beefsteak tomato')->sole();

        return [$organization, $location, $beefsteak];
    }
}
