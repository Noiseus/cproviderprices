<?php

namespace Database\Factories;

use App\Models\CanonicalProduct;
use App\Models\VendorItem;
use App\Models\VendorItemMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VendorItemMapping> */
class VendorItemMappingFactory extends Factory
{
    protected $model = VendorItemMapping::class;

    public function definition(): array
    {
        return ['vendor_item_id' => VendorItem::factory(), 'canonical_product_id' => CanonicalProduct::factory(), 'product_variant_id' => null, 'match_candidate_id' => null, 'reviewed_by' => null, 'supersedes_id' => null, 'match_class' => 'equivalent_product', 'confidence' => 'B', 'origin' => 'manual', 'matcher_version' => 'catalog-matcher-1.0.0', 'evidence' => ['stage' => 'manual'], 'is_current' => true, 'verified_at' => now(), 'superseded_at' => null];
    }
}
