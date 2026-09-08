<?php

namespace App\Models;

use App\Enums\OrganizationStatus;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property OrganizationStatus $status */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => OrganizationStatus::class];
    }

    /** @return HasMany<OrganizationMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /** @return HasMany<RestaurantLocation, $this> */
    public function restaurantLocations(): HasMany
    {
        return $this->hasMany(RestaurantLocation::class);
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
