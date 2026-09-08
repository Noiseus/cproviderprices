<?php

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Support\ApiProblem;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenantContext
{
    public function handle(Request $request, Closure $next, string $allowSuspended = 'false'): Response
    {
        $user = $request->user();
        $organization = $request->route('organization');

        if (! $user instanceof User || ! $organization instanceof Organization) {
            throw new ApiProblem('organization_unavailable', 'The organization is unavailable.', 404);
        }

        $membership = $user->memberships()
            ->whereBelongsTo($organization)
            ->first();

        if ($membership === null) {
            throw new ApiProblem('forbidden', 'You do not have access to this organization.', 403);
        }

        if ($membership->status !== MembershipStatus::Active) {
            throw new ApiProblem('membership_suspended', 'Your organization membership is not active.', 403);
        }

        if ($organization->status === OrganizationStatus::Closed) {
            throw new ApiProblem('organization_unavailable', 'The organization is unavailable.', 404);
        }

        if ($organization->status === OrganizationStatus::Suspended && $allowSuspended !== 'true') {
            throw new ApiProblem('organization_suspended', 'This organization is suspended.', 423, [
                'organization_slug' => $organization->slug,
            ]);
        }

        $context = new TenantContext($user, $organization, $membership);
        $request->attributes->set(TenantContext::class, $context);
        app()->instance(TenantContext::class, $context);

        return $next($request);
    }
}
