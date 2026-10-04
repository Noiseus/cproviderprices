<?php

namespace Database\Factories;

use App\Models\CanonicalProduct;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CanonicalProduct> */
class CanonicalProductFactory extends Factory
{
    protected $model = CanonicalProduct::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return ['category_id' => Category::factory(), 'english_name' => $name, 'normalized_key' => $name, 'attributes' => [], 'is_active' => true];
    }
}
