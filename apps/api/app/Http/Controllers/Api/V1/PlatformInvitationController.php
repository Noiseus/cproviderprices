<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Services\PlatformInvitationService;
use App\Support\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformInvitationController extends Controller
{
    public function store(
        Request $request,
        Organization $organization,
        PlatformInvitationService $invitations,
    ): JsonResponse {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);
        $invitation = $invitations->inviteInitialOwner($organization, $this->actor($request), $validated['email']);

        return response()->json(['invitation' => $this->payload($invitation)], 201);
    }

    public function resend(
        Request $request,
        Organization $organization,
        Invitation $invitation,
        PlatformInvitationService $invitations,
    ): JsonResponse {
        $replacement = $invitations->resend($organization, $invitation, $this->actor($request));

        return response()->json(['invitation' => $this->payload($replacement)]);
    }

    /** @return array<string, mixed> */
    private function payload(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'intended_email' => $invitation->intended_email,
            'intended_role' => $invitation->intended_role->value,
            'status' => $invitation->lifecycleStatus()->value,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'created_at' => $invitation->created_at->toIso8601String(),
        ];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new ApiProblem('unauthorized', 'Authentication is required.', 401);
        }

        return $actor;
    }
}
