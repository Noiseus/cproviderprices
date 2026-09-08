<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'code' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'name' => Str::title($name),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
