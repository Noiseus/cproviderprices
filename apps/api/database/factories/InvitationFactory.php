<?php

namespace Database\Factories;

use App\Enums\InvitationStatus;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Invitation> */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'intended_email' => fake()->unique()->safeEmail(),
            'intended_role' => Role::Buyer,
            'status' => InvitationStatus::Pending,
            'token_hash' => Invitation::hashPlaintextToken(Str::random(64)),
            'invited_by' => null,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'revoked_at' => null,
        ];
    }

    public function withPlaintextToken(string $plaintextToken): static
    {
        return $this->state(fn (): array => [
            'token_hash' => Invitation::hashPlaintextToken($plaintextToken),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => InvitationStatus::Expired,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
