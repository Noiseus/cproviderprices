<?php

namespace Database\Factories;

use App\Models\SupplierOffer;
use App\Models\VendorItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierOffer> */
class SupplierOfferFactory extends Factory
{
    protected $model = SupplierOffer::class;

    public function definition(): array
    {
        $vendor = VendorItem::factory()->create();
        $account = $vendor->supplierAccount;

        return [
            'organization_id' => $vendor->organization_id,
            'restaurant_location_id' => $account->restaurant_location_id,
            'supplier_account_id' => $account->id,
            'vendor_item_id' => $vendor->id,
            'current_price_observation_id' => null,
            'is_active' => true,
        ];
    }
}
