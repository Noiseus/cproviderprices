<?php

namespace App\Policies;

use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;

final class ImportRunPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->memberships()->where('organization_id', $organization->id)
            ->where('status', MembershipStatus::Active)->exists();
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->memberships()->where('organization_id', $organization->id)
            ->where('status', MembershipStatus::Active)
            ->whereIn('role', [Role::OrganizationOwner, Role::Buyer])->exists();
    }
}
