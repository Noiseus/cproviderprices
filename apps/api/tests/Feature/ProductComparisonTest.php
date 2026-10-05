<?php

namespace Tests\Feature;

use App\Enums\MatchClass;
use App\Enums\OfferAvailability;
use App\Models\CanonicalProduct;
use App\Models\Organization;
use App\Models\RestaurantLocation;
use App\Models\User;
use App\Services\OfferComparisonService;
use Database\Seeders\CatalogTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsComparisonFixtures;
use Tests\Concerns\BuildsTenantContext;
use Tests\TestCase;

final class ProductComparisonTest extends TestCase
{
    use BuildsComparisonFixtures;
    use BuildsTenantContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogTaxonomySeeder::class);
    }

    public function test_server_decimal_calculation_keeps_winner_concepts_distinct_and_traceable(): void
    {
        [$organization, $location, $product] = $this->context();
        $lowestCase = $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Lowest case'), $product, [
            'price' => '18.00', 'quantity' => '4535.9237', 'availability' => OfferAvailability::Unavailable,
        ]);
        $bestNormalized = $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Normalized winner'), $product, [
            'price' => '30.00', 'quantity' => '22679.6185', 'availability' => OfferAvailability::Unavailable,
        ]);
        $bestAvailable = $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Available winner'), $product, [
            'price' => '21.00', 'quantity' => '13607.7711', 'availability' => OfferAvailability::Available,
        ]);
        $alternative = $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Alternative'), $product, [
            'price' => '10.00', 'quantity' => '11339.80925', 'match_class' => MatchClass::ComparableAlternative,
        ]);
        $unsafe = $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Variable weight'), $product, [
            'price' => '50.00', 'quantity' => null,
        ]);
        $stale = $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Stale supplier'), $product, [
            'price' => '22.00', 'quantity' => '11339.80925', 'observed_at' => now()->subDays(15),
        ]);

        $payload = app(OfferComparisonService::class)->compare($organization->id, $location, $product, []);
        /** @var list<array<string, mixed>> $offers */
        $offers = $payload['offers'];
        $rows = collect($offers)->keyBy('id');

        $this->assertSame(['lowest_case_price'], $rows[$lowestCase->id]['winner_labels']);
        $this->assertSame(['best_normalized_price'], $rows[$bestNormalized->id]['winner_labels']);
        $this->assertSame(['best_available_option'], $rows[$bestAvailable->id]['winner_labels']);
        $this->assertSame('0.6', $rows[$bestNormalized->id]['normalized_price']);
        $this->assertSame('lb', $rows[$bestNormalized->id]['normalized_unit']);
        $this->assertContains('alternative_not_directly_interchangeable', $rows[$alternative->id]['warnings']);
        $this->assertSame([], $rows[$alternative->id]['winner_labels']);
        $this->assertNull($rows[$unsafe->id]['normalized_price']);
        $this->assertContains('normalized_price_unavailable', $rows[$unsafe->id]['warnings']);
        $this->assertSame('manual_import', $rows[$stale->id]['freshness']);
        $this->assertSame('price-comparison-1.0.0', $rows[$bestNormalized->id]['calculation']['formula_version']);
        $this->assertSame('30.00', $rows[$bestNormalized->id]['calculation']['source_price_evidence']['source_price']);
        $this->assertSame('comparison_fixture', $rows[$bestNormalized->id]['calculation']['match']['evidence']['stage']);
    }

    public function test_incompatible_dimensions_are_visible_but_never_receive_a_normalized_winner(): void
    {
        [$organization, $location, $product] = $this->context();
        $weight = $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Weight supplier'), $product, [
            'price' => '24.50', 'quantity' => '11339.80925', 'dimension' => 'weight', 'normalized_unit' => 'g',
        ]);
        $volume = $this->comparisonOffer($this->comparisonAccount($organization, $location, 'Volume supplier'), $product, [
            'price' => '24.50', 'quantity' => '3785.411784', 'dimension' => 'volume', 'normalized_unit' => 'mL',
        ]);

        $payload = app(OfferComparisonService::class)->compare($organization->id, $location, $product, []);
        /** @var list<array<string, mixed>> $offers */
        $offers = $payload['offers'];
        $rows = collect($offers)->keyBy('id');

        $this->assertContains('incompatible_normalized_dimensions', $payload['warnings']);
        $this->assertNotContains('best_normalized_price', $rows[$weight->id]['winner_labels']);
        $this->assertNotContains('best_normalized_price', $rows[$volume->id]['winner_labels']);
    }

    public function test_comparison_api_scopes_location_and_organization_on_the_server(): void
    {
        [$organization, $location, $product] = $this->context();
        $account = $this->comparisonAccount($organization, $location, 'Tenant A');
        $visible = $this->comparisonOffer($account, $product);
        $otherOrganization = Organization::factory()->create();
        $otherLocation = RestaurantLocation::factory()->for($otherOrganization)->create();
        $otherAccount = $this->comparisonAccount($otherOrganization, $otherLocation, 'Tenant B');
        $hidden = $this->comparisonOffer($otherAccount, $product);
        $user = User::factory()->create();
        $this->membership($user, $organization);

        $this->asUser($user)
            ->getJson("/api/v1/organizations/{$organization->id}/comparisons/{$product->id}?location_id={$location->id}")
            ->assertOk()
            ->assertJsonPath('offers.0.id', $visible->id)
            ->assertJsonMissing(['id' => $hidden->id]);
        $this->getJson("/api/v1/organizations/{$organization->id}/comparisons/{$product->id}?location_id={$otherLocation->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    /** @return array{Organization, RestaurantLocation, CanonicalProduct} */
    private function context(): array
    {
        $organization = Organization::factory()->create();
        $location = RestaurantLocation::factory()->for($organization)->create();
        $product = CanonicalProduct::query()->with('category')->where('normalized_key', 'beefsteak tomato')->sole();

        return [$organization, $location, $product];
    }
}
