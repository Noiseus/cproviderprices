<?php

namespace App\Support;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use App\Enums\Role;
use App\Models\User;

final class SafeDestination
{
    public static function afterLogin(User $user, ?string $requested): string
    {
        if ($user->platform_role === Role::PlatformAdmin
            && is_string($requested)
            && self::isInternalPath($requested)
            && ($requested === '/platform' || str_starts_with($requested, '/platform/'))) {
            return $requested;
        }

        $memberships = $user->memberships()
            ->where('status', MembershipStatus::Active)
            ->with('organization')
            ->get()
            ->filter(fn ($membership): bool => $membership->organization->status !== OrganizationStatus::Closed);

        if (is_string($requested) && self::isInternalPath($requested)) {
            foreach ($memberships as $membership) {
                if (str_starts_with($requested, '/o/'.$membership->organization->slug.'/')) {
                    return $requested;
                }
            }
        }

        $membership = $memberships->first(
            fn ($candidate): bool => $candidate->organization->status === OrganizationStatus::Active
        ) ?? $memberships->first();

        if ($membership === null && $user->platform_role === Role::PlatformAdmin) {
            return '/platform';
        }

        return $membership === null ? '/' : '/o/'.$membership->organization->slug.'/dashboard';
    }

    public static function forOrganization(string $slug, ?string $requested): string
    {
        if (is_string($requested)
            && self::isInternalPath($requested)
            && str_starts_with($requested, '/o/'.$slug.'/')) {
            return $requested;
        }

        return '/o/'.$slug.'/dashboard';
    }

    public static function isInternalPath(string $path): bool
    {
        return str_starts_with($path, '/')
            && ! str_starts_with($path, '//')
            && ! str_contains($path, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1;
    }
}
