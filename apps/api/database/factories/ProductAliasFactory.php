<?php

namespace Database\Factories;

use App\Models\CanonicalProduct;
use App\Models\ProductAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductAlias> */
class ProductAliasFactory extends Factory
{
    protected $model = ProductAlias::class;

    public function definition(): array
    {
        return ['canonical_product_id' => CanonicalProduct::factory(), 'product_variant_id' => null, 'locale' => 'en', 'alias' => fake()->unique()->words(2, true)];
    }
}
