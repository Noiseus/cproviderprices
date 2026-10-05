<?php

namespace Database\Factories;

use App\Models\CanonicalProduct;
use App\Models\SavedList;
use App\Models\SavedListItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavedListItem> */
class SavedListItemFactory extends Factory
{
    protected $model = SavedListItem::class;

    public function definition(): array
    {
        $list = SavedList::factory()->create();
        $product = CanonicalProduct::factory()->create();

        return [
            'saved_list_id' => $list->id,
            'organization_id' => $list->organization_id,
            'restaurant_location_id' => $list->restaurant_location_id,
            'canonical_product_id' => $product->id,
            'product_variant_id' => null,
            'selected_offer_id' => null,
            'quantity_target' => '1.000000',
            'preferred_unit' => null,
            'notes' => null,
            'sort_order' => 1,
            'version' => 1,
            'created_by' => $list->created_by,
            'updated_by' => $list->updated_by,
        ];
    }
}
