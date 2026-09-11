<?php

namespace App\Services;

use App\Enums\InvitationStatus;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationOwnerInvitation;
use App\Support\ApiProblem;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class PlatformInvitationService
{
    public function inviteInitialOwner(Organization $organization, User $actor, string $email): Invitation
    {
        $email = mb_strtolower(trim($email));
        $this->assertNoActiveOwner($organization);

        $active = $organization->invitations()
            ->where('status', InvitationStatus::Pending)
            ->where('intended_role', Role::OrganizationOwner)
            ->where('expires_at', '>', now())
            ->first();

        if ($active !== null) {
            throw new ApiProblem('initial_owner_invitation_exists', 'An active initial-owner invitation already exists. Resend or revoke it before creating another.', 409);
        }

        return $this->replace($organization, $actor, $email, null, 'invitation.initial_owner_created');
    }

    public function resend(Organization $organization, Invitation $invitation, User $actor): Invitation
    {
        if ($invitation->organization_id !== $organization->id
            || $invitation->intended_role !== Role::OrganizationOwner) {
            throw new ApiProblem('invitation_unavailable', 'The invitation is unavailable.', 404);
        }

        $this->assertNoActiveOwner($organization);

        return $this->replace(
            $organization,
            $actor,
            $invitation->intended_email,
            $invitation,
            'invitation.initial_owner_replaced',
        );
    }

    private function assertNoActiveOwner(Organization $organization): void
    {
        $hasOwner = $organization->memberships()
            ->where('role', Role::OrganizationOwner)
            ->where('status', MembershipStatus::Active)
            ->exists();

        if ($hasOwner) {
            throw new ApiProblem('initial_owner_exists', 'This organization already has an active owner.', 409);
        }
    }

    private function replace(
        Organization $organization,
        User $actor,
        string $email,
        ?Invitation $replaced,
        string $action,
    ): Invitation {
        $plaintextToken = Str::random(64);

        $invitation = DB::transaction(function () use ($organization, $actor, $email, $replaced, $action, $plaintextToken): Invitation {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();

            $organization->invitations()
                ->where('status', InvitationStatus::Pending)
                ->where('intended_role', Role::OrganizationOwner)
                ->lockForUpdate()
                ->get()
                ->each(function (Invitation $pending): void {
                    $pending->update([
                        'status' => InvitationStatus::Revoked,
                        'revoked_at' => now(),
                    ]);
                });

            $newInvitation = $organization->invitations()->create([
                'intended_email' => $email,
                'intended_role' => Role::OrganizationOwner,
                'status' => InvitationStatus::Pending,
                'token_hash' => Invitation::hashPlaintextToken($plaintextToken),
                'invited_by' => $actor->id,
                'expires_at' => now()->addDays(7),
            ]);

            Audit::record(
                $action,
                $actor,
                $organization->id,
                'invitation',
                $newInvitation->id,
                array_filter([
                    'intended_email' => $email,
                    'intended_role' => Role::OrganizationOwner->value,
                    'replaced_invitation_id' => $replaced?->id,
                ], static fn (mixed $value): bool => $value !== null),
            );

            return $newInvitation;
        });

        Notification::route('mail', $email)->notify(
            new OrganizationOwnerInvitation($organization, $plaintextToken),
        );

        return $invitation;
    }
}
