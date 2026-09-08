<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PlanEntitlement> */
class PlanEntitlementFactory extends Factory
{
    protected $model = PlanEntitlement::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'key' => 'feature.'.Str::lower(Str::random(10)),
            'value' => ['enabled' => true],
        ];
    }
}
