<?php

namespace App\Policies;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;

final class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $organization->status !== OrganizationStatus::Closed
            && $user->memberships()->whereBelongsTo($organization)
                ->where('status', MembershipStatus::Active)->exists();
    }

    public function update(User $user, Organization $organization): bool
    {
        return $organization->status === OrganizationStatus::Active
            && $user->memberships()->whereBelongsTo($organization)
                ->where('status', MembershipStatus::Active)
                ->where('role', Role::OrganizationOwner)->exists();
    }
}
