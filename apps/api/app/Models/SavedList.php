<?php

namespace App\Models;

use App\Enums\SavedListStatus;
use Database\Factories\SavedListFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant/location-scoped purchasing intent. Price and offer state is derived
 * at read time and never copied into this durable record.
 *
 * @property SavedListStatus $status
 */
class SavedList extends Model
{
    /** @use HasFactory<SavedListFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'restaurant_location_id',
        'name',
        'status',
        'version',
        'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SavedListStatus::class,
            'version' => 'integer',
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasMany<SavedListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SavedListItem::class);
    }
}
