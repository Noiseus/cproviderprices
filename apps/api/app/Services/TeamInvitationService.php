<?php

namespace App\Services;

use App\Enums\InvitationStatus;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationInvitation;
use App\Support\ApiProblem;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class TeamInvitationService
{
    public function create(Organization $organization, User $actor, string $email, Role $role): Invitation
    {
        return $this->replace($organization, $actor, mb_strtolower(trim($email)), $role);
    }

    public function resend(Organization $organization, Invitation $invitation, User $actor): Invitation
    {
        if ($invitation->organization_id !== $organization->id || ! $invitation->isActive()) {
            throw new ApiProblem('invitation_unavailable', 'The invitation is unavailable.', 404);
        }

        return $this->replace($organization, $actor, $invitation->intended_email, $invitation->intended_role);
    }

    private function replace(Organization $organization, User $actor, string $email, Role $role): Invitation
    {
        $plaintextToken = Str::random(64);
        $invitation = DB::transaction(function () use ($organization, $actor, $email, $role, $plaintextToken): Invitation {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $existingUser = User::query()->where('email', $email)->first();
            if ($existingUser !== null && $organization->memberships()->where('user_id', $existingUser->id)->exists()) {
                throw new ApiProblem('membership_exists', 'This person already has an organization membership.', 409);
            }

            $organization->invitations()->where('status', InvitationStatus::Pending)
                ->where('intended_email', $email)->lockForUpdate()->get()
                ->each(fn (Invitation $pending) => $pending->update([
                    'status' => InvitationStatus::Revoked,
                    'revoked_at' => now(),
                ]));

            $created = $organization->invitations()->create([
                'intended_email' => $email,
                'intended_role' => $role,
                'status' => InvitationStatus::Pending,
                'token_hash' => Invitation::hashPlaintextToken($plaintextToken),
                'invited_by' => $actor->id,
                'expires_at' => now()->addDays(7),
            ]);
            Audit::record('invitation.created', $actor, $organization->id, 'invitation', $created->id, [
                'intended_email' => $email,
                'intended_role' => $role->value,
            ]);

            return $created;
        });

        Notification::route('mail', $email)->notify(new OrganizationInvitation($organization, $plaintextToken));

        return $invitation;
    }
}
