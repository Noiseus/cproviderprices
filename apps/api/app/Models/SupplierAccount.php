<?php

namespace App\Models;

use App\Enums\SupplierAccountStatus;
use App\Enums\SupplierRefreshCadence;
use App\Enums\SupplierSourceMethod;
use Carbon\CarbonImmutable;
use Database\Factories\SupplierAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property SupplierAccountStatus $status
 * @property SupplierSourceMethod $source_method
 * @property SupplierRefreshCadence $expected_refresh_cadence
 * @property CarbonImmutable|null $last_successful_sync_at
 * @property SupplierDefinition $supplierDefinition
 * @property RestaurantLocation $restaurantLocation
 */
class SupplierAccount extends Model
{
    /** @use HasFactory<SupplierAccountFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'restaurant_location_id',
        'supplier_definition_id',
        'display_name',
        'masked_account_reference',
        'source_method',
        'expected_refresh_cadence',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_method' => SupplierSourceMethod::class,
            'expected_refresh_cadence' => SupplierRefreshCadence::class,
            'status' => SupplierAccountStatus::class,
            'last_successful_sync_at' => 'immutable_datetime',
        ];
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

    /** @return BelongsTo<SupplierDefinition, $this> */
    public function supplierDefinition(): BelongsTo
    {
        return $this->belongsTo(SupplierDefinition::class);
    }

    /** @return HasMany<VendorItem, $this> */
    public function vendorItems(): HasMany
    {
        return $this->hasMany(VendorItem::class);
    }

    /** @return HasMany<SupplierOffer, $this> */
    public function supplierOffers(): HasMany
    {
        return $this->hasMany(SupplierOffer::class);
    }
}
