<?php

namespace Database\Factories;

use App\Models\SupplierDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SupplierDefinition> */
class SupplierDefinitionFactory extends Factory
{
    protected $model = SupplierDefinition::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return ['code' => Str::slug($name), 'name' => $name, 'is_active' => true];
    }
}
