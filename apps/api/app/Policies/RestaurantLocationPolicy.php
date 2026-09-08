<?php

namespace App\Policies;

use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Models\OrganizationMembership;
use App\Models\RestaurantLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RestaurantLocationPolicy
{
    public function view(User $user, RestaurantLocation $location): bool
    {
        return $this->membership($user, $location)->exists();
    }

    public function update(User $user, RestaurantLocation $location): bool
    {
        return $this->membership($user, $location)->where('role', Role::OrganizationOwner)->exists();
    }

    public function delete(User $user, RestaurantLocation $location): bool
    {
        return $this->update($user, $location);
    }

    /** @return HasMany<OrganizationMembership, User> */
    private function membership(User $user, RestaurantLocation $location): HasMany
    {
        return $user->memberships()
            ->where('organization_id', $location->organization_id)
            ->where('status', MembershipStatus::Active);
    }
}
