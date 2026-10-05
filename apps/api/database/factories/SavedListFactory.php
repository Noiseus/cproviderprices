<?php

namespace Database\Factories;

use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Enums\SavedListStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\RestaurantLocation;
use App\Models\SavedList;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavedList> */
class SavedListFactory extends Factory
{
    protected $model = SavedList::class;

    public function definition(): array
    {
        $organization = Organization::factory()->create();
        $location = RestaurantLocation::factory()->for($organization)->create();
        $user = User::factory()->create();
        OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => Role::Buyer,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return [
            'organization_id' => $organization->id,
            'restaurant_location_id' => $location->id,
            'name' => 'Purchasing list '.$this->faker->unique()->word(),
            'status' => SavedListStatus::Active,
            'version' => 1,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ];
    }
}
