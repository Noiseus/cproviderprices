<?php

namespace Database\Factories;

use App\Models\CanonicalProduct;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariant> */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        $code = 'variant-'.fake()->unique()->numerify('####');

        return ['canonical_product_id' => CanonicalProduct::factory(), 'code' => $code, 'normalized_key' => $code, 'attributes' => [], 'is_active' => true];
    }
}
