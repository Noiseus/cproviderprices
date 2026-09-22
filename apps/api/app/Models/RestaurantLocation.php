<?php

namespace App\Models;

use App\Enums\LocationStatus;
use Database\Factories\RestaurantLocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property LocationStatus $status */
class RestaurantLocation extends Model
{
    /** @use HasFactory<RestaurantLocationFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'status',
        'timezone',
        'address_line_1',
        'address_line_2',
        'city',
        'region',
        'postal_code',
        'country_code',
        'external_reference',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => LocationStatus::class];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<SupplierAccount, $this> */
    public function supplierAccounts(): HasMany
    {
        return $this->hasMany(SupplierAccount::class);
    }
}
