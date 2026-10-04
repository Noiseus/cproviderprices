<?php

namespace Database\Factories;

use App\Models\CanonicalProduct;
use App\Models\ProductIdentifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductIdentifier> */
class ProductIdentifierFactory extends Factory
{
    protected $model = ProductIdentifier::class;

    public function definition(): array
    {
        return ['canonical_product_id' => CanonicalProduct::factory(), 'product_variant_id' => null, 'identifier_type' => 'gtin', 'identifier_value' => fake()->unique()->numerify('###########')];
    }
}
