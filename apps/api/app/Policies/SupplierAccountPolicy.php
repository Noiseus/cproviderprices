<?php

namespace App\Policies;

use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\SupplierAccount;
use App\Models\User;

final class SupplierAccountPolicy
{
    public function create(User $user, Organization $organization): bool
    {
        return $this->membership($user, $organization->id)->where('role', Role::OrganizationOwner)->exists();
    }

    public function view(User $user, SupplierAccount $account): bool
    {
        return $this->membership($user, $account->organization_id)->exists();
    }

    public function update(User $user, SupplierAccount $account): bool
    {
        return $this->membership($user, $account->organization_id)->where('role', Role::OrganizationOwner)->exists();
    }

    private function membership(User $user, string $organizationId): mixed
    {
        return $user->memberships()->where('organization_id', $organizationId)
            ->where('status', MembershipStatus::Active);
    }
}
