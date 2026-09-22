<?php

namespace Database\Factories;

use App\Enums\LocationStatus;
use App\Models\Organization;
use App\Models\RestaurantLocation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<RestaurantLocation> */
class RestaurantLocationFactory extends Factory
{
    protected $model = RestaurantLocation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->company().' Location',
            'code' => Str::upper(Str::random(8)),
            'status' => LocationStatus::Active,
            'timezone' => 'America/New_York',
            'address_line_1' => fake()->streetAddress(),
            'address_line_2' => null,
            'city' => fake()->city(),
            'region' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'country_code' => 'US',
            'external_reference' => null,
        ];
    }
}
