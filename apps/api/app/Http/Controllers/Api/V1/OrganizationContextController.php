<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Support\ApiProblem;
use App\Support\Audit;
use App\Support\SafeDestination;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrganizationContextController extends Controller
{
    public function show(TenantContext $context): JsonResponse
    {
        return response()->json(['organization_context' => [
            'organization' => [
                'id' => $context->organization->id,
                'name' => $context->organization->name,
                'slug' => $context->organization->slug,
                'status' => $context->organization->status->value,
            ],
            'membership' => [
                'id' => $context->membership->id,
                'role' => $context->membership->role->value,
                'status' => $context->membership->status->value,
            ],
        ]]);
    }

    public function status(TenantContext $context): JsonResponse
    {
        return response()->json(['organization_status' => [
            'name' => $context->organization->name,
            'slug' => $context->organization->slug,
            'status' => $context->organization->status->value,
            'normal_access_available' => $context->organization->status === OrganizationStatus::Active,
        ]]);
    }

    public function switch(Request $request, TenantContext $context): JsonResponse
    {
        $activeCount = $context->user->memberships()
            ->where('status', MembershipStatus::Active)
            ->whereHas('organization', fn ($query) => $query->where('status', OrganizationStatus::Active))
            ->count();

        if ($activeCount < 2) {
            throw new ApiProblem('organization_switch_unavailable', 'Organization switching is unavailable.', 422);
        }

        $validated = $request->validate(['intended_destination' => ['nullable', 'string', 'max:2048']]);
        $request->session()->put('selected_organization_id', $context->organization->id);
        Audit::record(
            'organization.switched',
            $context->user,
            $context->organization->id,
            'organization',
            $context->organization->id,
        );

        return response()->json([
            'destination' => SafeDestination::forOrganization(
                $context->organization->slug,
                $validated['intended_destination'] ?? null,
            ),
        ]);
    }
}
