<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvitationStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Organization;
use App\Services\TeamInvitationService;
use App\Support\ApiProblem;
use App\Support\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class TeamController extends Controller
{
    public function index(TenantContext $context, Organization $organization): JsonResponse
    {
        $members = $context->organization->memberships()->with('user')
            ->where('status', 'active')->orderBy('created_at')->get()->map(fn ($membership): array => [
                'id' => $membership->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'role' => $membership->role->value,
                'status' => $membership->status->value,
                'joined_at' => $membership->joined_at?->toIso8601String(),
            ])->all();
        $invitations = $context->organization->invitations()->where('status', InvitationStatus::Pending)
            ->orderByDesc('created_at')->get()->map(fn (Invitation $invitation): array => $this->invitationPayload($invitation))->all();

        return response()->json(['team' => ['members' => $members, 'pending_invitations' => $invitations]]);
    }

    public function invite(Request $request, TenantContext $context, Organization $organization, TeamInvitationService $service): JsonResponse
    {
        Gate::authorize('create', [Invitation::class, $context->organization]);
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(Role::organizationValues())],
            'high_risk_confirmed' => ['sometimes', 'boolean'],
        ]);
        $role = Role::from($validated['role']);
        if ($role === Role::OrganizationOwner && ($validated['high_risk_confirmed'] ?? false) !== true) {
            throw new ApiProblem('high_risk_confirmation_required', 'Owner invitations require explicit confirmation.', 422);
        }

        $invitation = $service->create($context->organization, $context->user, $validated['email'], $role);

        return response()->json(['invitation' => $this->invitationPayload($invitation)], 201);
    }

    public function resend(TenantContext $context, Organization $organization, string $invitation, TeamInvitationService $service): JsonResponse
    {
        $target = $this->find($context, $invitation);
        Gate::authorize('update', $target);

        return response()->json(['invitation' => $this->invitationPayload(
            $service->resend($context->organization, $target, $context->user)
        )]);
    }

    public function revoke(TenantContext $context, Organization $organization, string $invitation): JsonResponse
    {
        $target = $this->find($context, $invitation);
        Gate::authorize('delete', $target);
        $target->update(['status' => InvitationStatus::Revoked, 'revoked_at' => now()]);
        Audit::record('invitation.revoked', $context->user, $context->organization->id, 'invitation', $target->id, [
            'intended_email' => $target->intended_email,
            'intended_role' => $target->intended_role->value,
        ]);

        return response()->json(['message' => 'Invitation revoked.']);
    }

    private function find(TenantContext $context, string $id): Invitation
    {
        $invitation = $context->organization->invitations()->whereKey($id)->first();
        if (! $invitation instanceof Invitation) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $invitation;
    }

    /** @return array<string, mixed> */
    private function invitationPayload(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'intended_email' => $invitation->intended_email,
            'intended_role' => $invitation->intended_role->value,
            'status' => $invitation->lifecycleStatus()->value,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ];
    }
}
