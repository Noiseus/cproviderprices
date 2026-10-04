<?php

namespace App\Models;

use Database\Factories\SupplierOfferFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-private logical offer identity. Price facts belong to immutable
 * observations; this model only carries the current-observation pointer.
 *
 * @property bool $is_active
 * @property PriceObservation|null $currentPriceObservation
 */
class SupplierOffer extends Model
{
    /** @use HasFactory<SupplierOfferFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'restaurant_location_id',
        'supplier_account_id',
        'vendor_item_id',
        'current_price_observation_id',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<RestaurantLocation, $this> */
    public function restaurantLocation(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class);
    }

    /** @return BelongsTo<SupplierAccount, $this> */
    public function supplierAccount(): BelongsTo
    {
        return $this->belongsTo(SupplierAccount::class);
    }

    /** @return BelongsTo<VendorItem, $this> */
    public function vendorItem(): BelongsTo
    {
        return $this->belongsTo(VendorItem::class);
    }

    /** @return BelongsTo<PriceObservation, $this> */
    public function currentPriceObservation(): BelongsTo
    {
        return $this->belongsTo(PriceObservation::class, 'current_price_observation_id');
    }

    /** @return HasMany<PriceObservation, $this> */
    public function priceObservations(): HasMany
    {
        return $this->hasMany(PriceObservation::class);
    }
}
