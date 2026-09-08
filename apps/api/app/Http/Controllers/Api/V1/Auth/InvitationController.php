<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\InvitationStatus;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuthSession;
use App\Support\SafeDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

final class InvitationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $invitation = $this->find($token);
        $this->assertUsable($invitation);

        return response()->json(['invitation' => [
            'intended_email' => $invitation->intended_email,
            'intended_role' => $invitation->intended_role->value,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'organization' => [
                'id' => $invitation->organization->id,
                'name' => $invitation->organization->name,
                'slug' => $invitation->organization->slug,
            ],
        ]]);
    }

    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:32', 'max:512'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'organization_id' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
        ]);

        $result = DB::transaction(function () use ($validated): array {
            $invitation = Invitation::query()
                ->with('organization')
                ->where('token_hash', Invitation::hashPlaintextToken($validated['token']))
                ->lockForUpdate()
                ->first();

            if (! $invitation instanceof Invitation) {
                throw new ApiProblem('invitation_invalid', 'This invitation link is invalid.', 404);
            }

            $this->assertUsable($invitation);

            if (isset($validated['email'])
                && mb_strtolower(trim($validated['email'])) !== $invitation->intended_email) {
                throw new ApiProblem('invitation_identity_mismatch', 'This invitation is intended for a different email address.', 422);
            }

            $user = User::query()->where('email', $invitation->intended_email)->lockForUpdate()->first();

            if ($user instanceof User) {
                if ($user->status !== UserStatus::Active) {
                    throw new ApiProblem('account_suspended', 'This account is suspended.', 403);
                }

                if (! Hash::check($validated['password'], $user->password)) {
                    throw new ApiProblem('invitation_credentials_invalid', 'The invitation could not be accepted with those credentials.', 422);
                }
            } else {
                $user = User::query()->create([
                    'name' => $validated['name'],
                    'email' => $invitation->intended_email,
                    'password' => $validated['password'],
                    'status' => UserStatus::Active,
                ]);
                $user->markEmailAsVerified();
            }

            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            $membership = OrganizationMembership::query()
                ->whereBelongsTo($invitation->organization)
                ->whereBelongsTo($user)
                ->lockForUpdate()
                ->first();

            if ($membership !== null && $membership->status !== MembershipStatus::Invited) {
                throw new ApiProblem('invitation_already_used', 'This invitation has already been used.', 409);
            }

            if ($membership === null) {
                $membership = new OrganizationMembership([
                    'organization_id' => $invitation->organization_id,
                    'user_id' => $user->id,
                ]);
            }

            $membership->fill([
                'role' => $invitation->intended_role,
                'status' => MembershipStatus::Active,
                'invited_by' => $invitation->invited_by,
                'joined_at' => now(),
            ])->save();

            $invitation->update([
                'status' => InvitationStatus::Accepted,
                'accepted_at' => now(),
            ]);

            return [$user, $invitation->organization];
        });

        [$user, $organization] = $result;
        AuthSession::start($request, $user);

        return response()->json([
            'message' => 'Invitation accepted.',
            'destination' => SafeDestination::forOrganization($organization->slug, null),
        ]);
    }

    private function find(string $token): Invitation
    {
        $invitation = Invitation::query()
            ->with('organization')
            ->where('token_hash', Invitation::hashPlaintextToken($token))
            ->first();

        if (! $invitation instanceof Invitation) {
            throw new ApiProblem('invitation_invalid', 'This invitation link is invalid.', 404);
        }

        return $invitation;
    }

    private function assertUsable(Invitation $invitation): void
    {
        $lifecycle = $invitation->lifecycleStatus();

        if ($lifecycle === InvitationStatus::Expired) {
            if ($invitation->status === InvitationStatus::Pending) {
                $invitation->update(['status' => InvitationStatus::Expired]);
            }

            throw new ApiProblem('invitation_expired', 'This invitation has expired.', 410);
        }

        if ($lifecycle !== InvitationStatus::Pending) {
            throw new ApiProblem(
                $lifecycle === InvitationStatus::Accepted ? 'invitation_already_used' : 'invitation_unavailable',
                $lifecycle === InvitationStatus::Accepted ? 'This invitation has already been used.' : 'This invitation is unavailable.',
                409,
            );
        }

        if ($invitation->organization->status === OrganizationStatus::Suspended) {
            throw new ApiProblem('organization_suspended', 'This organization is suspended.', 423);
        }

        if ($invitation->organization->status !== OrganizationStatus::Active) {
            throw new ApiProblem('organization_unavailable', 'This organization is unavailable.', 404);
        }
    }
}
