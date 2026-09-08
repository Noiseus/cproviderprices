<?php

namespace Database\Factories;

use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationMembership> */
class OrganizationMembershipFactory extends Factory
{
    protected $model = OrganizationMembership::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => Role::Buyer,
            'status' => MembershipStatus::Active,
            'invited_by' => null,
            'joined_at' => now(),
        ];
    }

    public function invited(): static
    {
        return $this->state(fn (): array => [
            'status' => MembershipStatus::Invited,
            'joined_at' => null,
        ]);
    }
}
