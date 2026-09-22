<?php

namespace App\Policies;

use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;

final class InvitationPolicy
{
    public function create(User $user, Organization $organization): bool
    {
        return $this->isOwner($user, $organization->id);
    }

    public function view(User $user, Invitation $invitation): bool
    {
        return $this->isOwner($user, $invitation->organization_id);
    }

    public function update(User $user, Invitation $invitation): bool
    {
        return $this->isOwner($user, $invitation->organization_id);
    }

    public function delete(User $user, Invitation $invitation): bool
    {
        return $this->isOwner($user, $invitation->organization_id);
    }

    private function isOwner(User $user, string $organizationId): bool
    {
        return $user->memberships()->where('organization_id', $organizationId)
            ->where('status', MembershipStatus::Active)
            ->where('role', Role::OrganizationOwner)->exists();
    }
}
