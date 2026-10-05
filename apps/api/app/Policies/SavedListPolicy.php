<?php

namespace App\Policies;

use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SavedList;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SavedListPolicy
{
    public function view(User $user, SavedList $list): bool
    {
        return $this->activeMembership($user, $list->organization_id)->exists();
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->activeMembership($user, $organization->id)
            ->whereIn('role', [Role::OrganizationOwner, Role::Buyer])
            ->exists();
    }

    public function update(User $user, SavedList $list): bool
    {
        return $this->activeMembership($user, $list->organization_id)
            ->whereIn('role', [Role::OrganizationOwner, Role::Buyer])
            ->exists();
    }

    public function delete(User $user, SavedList $list): bool
    {
        return $this->update($user, $list);
    }

    /** @return HasMany<OrganizationMembership, User> */
    private function activeMembership(User $user, string $organizationId): HasMany
    {
        return $user->memberships()
            ->where('organization_id', $organizationId)
            ->where('status', MembershipStatus::Active);
    }
}
