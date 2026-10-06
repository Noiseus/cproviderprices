<?php

namespace App\Support;

use App\Enums\MembershipStatus;
use App\Models\OrganizationMembership;
use App\Models\User;

final class SessionPayload
{
    /** @return array<string, mixed> */
    public static function forUser(User $user): array
    {
        $memberships = $user->memberships()
            ->where('status', MembershipStatus::Active)
            ->with('organization')
            ->get();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
                'platform_role' => $user->platform_role?->value,
                'preferred_language' => $user->preferred_language->value,
            ],
            'organizations' => $memberships->map(fn (OrganizationMembership $membership): array => [
                'id' => $membership->organization->id,
                'name' => $membership->organization->name,
                'slug' => $membership->organization->slug,
                'status' => $membership->organization->status->value,
                'role' => $membership->role->value,
            ])->values()->all(),
        ];
    }
}
