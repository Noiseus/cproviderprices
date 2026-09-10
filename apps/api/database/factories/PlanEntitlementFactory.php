<?php

namespace Database\Factories;

use App\Enums\EntitlementKey;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanEntitlement> */
class PlanEntitlementFactory extends Factory
{
    protected $model = PlanEntitlement::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $key = fake()->randomElement(EntitlementKey::cases());

        return [
            'plan_id' => Plan::factory(),
            'key' => $key,
            'value' => $key->isLimit() ? ['limit' => 5] : ['enabled' => true],
        ];
    }
}
