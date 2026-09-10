<?php

namespace Tests\Concerns;

use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

trait BuildsTenantContext
{
    private function membership(
        User $user,
        Organization $organization,
        Role $role = Role::Buyer,
        MembershipStatus $status = MembershipStatus::Active,
    ): OrganizationMembership {
        return OrganizationMembership::factory()->for($user)->for($organization)->create([
            'role' => $role,
            'status' => $status,
            'joined_at' => $status === MembershipStatus::Invited ? null : now(),
        ]);
    }

    private function asUser(User $user): static
    {
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);

        return $this;
    }
}
