<?php

namespace App\Policies;

use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Models\OrganizationMembership;
use App\Models\User;

final class OrganizationMembershipPolicy
{
    public function view(User $user, OrganizationMembership $membership): bool
    {
        return $user->memberships()->where('organization_id', $membership->organization_id)
            ->where('status', MembershipStatus::Active)->exists();
    }

    public function update(User $user, OrganizationMembership $membership): bool
    {
        return $user->memberships()->where('organization_id', $membership->organization_id)
            ->where('status', MembershipStatus::Active)
            ->where('role', Role::OrganizationOwner)->exists();
    }

    public function delete(User $user, OrganizationMembership $membership): bool
    {
        return $this->update($user, $membership) && $membership->user_id !== $user->id;
    }
}
