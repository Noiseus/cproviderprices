<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Support\ApiProblem;
use App\Support\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class OrganizationMembershipController extends Controller
{
    public function update(Request $request, TenantContext $context, Organization $organization, string $membership): JsonResponse
    {
        $target = $context->organization->memberships()->whereKey($membership)->first();

        if (! $target instanceof OrganizationMembership) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        if (! Gate::allows('update', $target)) {
            Audit::record('authorization.high_risk_denied', $context->user, $context->organization->id,
                'organization_membership', $target->id, ['ability' => 'update']);
            throw new ApiProblem('forbidden', 'You are not authorized to perform this action.', 403);
        }

        $validated = $request->validate(['role' => ['required', Rule::in(Role::organizationValues())]]);
        $from = $target->role->value;
        DB::transaction(function () use ($context, $target, $validated): void {
            Organization::query()->whereKey($context->organization->id)->lockForUpdate()->firstOrFail();
            $locked = $context->organization->memberships()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($locked->role === Role::OrganizationOwner && $validated['role'] !== Role::OrganizationOwner->value) {
                $this->assertAnotherActiveOwner($context, $locked->id);
            }
            $locked->update(['role' => $validated['role']]);
        });
        $target->refresh();
        Audit::record('membership.role_changed', $context->user, $context->organization->id,
            'organization_membership', $target->id, ['from' => $from, 'to' => $target->role->value]);

        return response()->json(['membership' => [
            'id' => $target->id,
            'role' => $target->role->value,
            'status' => $target->status->value,
        ]]);
    }

    public function destroy(TenantContext $context, Organization $organization, string $membership): JsonResponse
    {
        $target = $context->organization->memberships()->whereKey($membership)->first();
        if (! $target instanceof OrganizationMembership) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }
        if (! Gate::allows('delete', $target)) {
            throw new ApiProblem('forbidden', 'You are not authorized to perform this action.', 403);
        }

        DB::transaction(function () use ($context, $target): void {
            Organization::query()->whereKey($context->organization->id)->lockForUpdate()->firstOrFail();
            $locked = $context->organization->memberships()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($locked->role === Role::OrganizationOwner && $locked->status === MembershipStatus::Active) {
                $this->assertAnotherActiveOwner($context, $locked->id);
            }
            $locked->update(['status' => MembershipStatus::Left]);
        });
        Audit::record('membership.revoked', $context->user, $context->organization->id,
            'organization_membership', $target->id, ['role' => $target->role->value]);

        return response()->json(['message' => 'Membership revoked.']);
    }

    private function assertAnotherActiveOwner(TenantContext $context, string $excludedId): void
    {
        $anotherOwner = $context->organization->memberships()->whereKeyNot($excludedId)
            ->where('status', MembershipStatus::Active)
            ->where('role', Role::OrganizationOwner)->exists();
        if (! $anotherOwner) {
            throw new ApiProblem('last_owner_required', 'The organization must retain at least one active owner.', 409);
        }
    }
}
