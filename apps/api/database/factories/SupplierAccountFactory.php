<?php

namespace Database\Factories;

use App\Enums\SupplierAccountStatus;
use App\Enums\SupplierRefreshCadence;
use App\Enums\SupplierSourceMethod;
use App\Models\Organization;
use App\Models\RestaurantLocation;
use App\Models\SupplierAccount;
use App\Models\SupplierDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierAccount> */
class SupplierAccountFactory extends Factory
{
    protected $model = SupplierAccount::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $organization = Organization::factory()->create();
        $location = RestaurantLocation::factory()->for($organization)->create();

        return [
            'organization_id' => $organization->id,
            'restaurant_location_id' => $location->id,
            'supplier_definition_id' => SupplierDefinition::factory(),
            'display_name' => fake()->company().' account',
            'masked_account_reference' => 'ending '.fake()->numerify('####'),
            'source_method' => SupplierSourceMethod::ManualImport,
            'expected_refresh_cadence' => SupplierRefreshCadence::Weekly,
            'status' => SupplierAccountStatus::Active,
            'last_successful_sync_at' => null,
        ];
    }
}
