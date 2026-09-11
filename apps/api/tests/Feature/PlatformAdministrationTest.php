<?php

namespace Tests\Feature;

use App\Enums\InvitationStatus;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\RestaurantLocation;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\OrganizationOwnerInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTenantContext;
use Tests\TestCase;

final class PlatformAdministrationTest extends TestCase
{
    use BuildsTenantContext;
    use RefreshDatabase;

    public function test_only_an_explicit_platform_admin_can_access_platform_apis(): void
    {
        $organization = Organization::factory()->create();

        $this->getJson('/api/v1/platform/overview')->assertUnauthorized();

        foreach ([Role::OrganizationOwner, Role::Buyer, Role::Viewer] as $role) {
            $user = User::factory()->create();
            $this->membership($user, $organization, $role);
            $this->asUser($user)->getJson('/api/v1/platform/overview')
                ->assertForbidden()->assertJsonPath('error.code', 'platform_scope_required');
        }

        $admin = User::factory()->platformAdmin()->create();
        $this->asUser($admin)->getJson('/api/v1/platform/overview')
            ->assertOk()->assertJsonStructure(['platform_overview' => ['organization_counts', 'recent_organizations']]);
    }

    public function test_platform_list_uses_validated_stable_server_pagination_and_filters(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $plan = Plan::factory()->create(['code' => 'platform-list-plan']);
        $active = Organization::factory()->create(['name' => 'Alpha Restaurant', 'status' => OrganizationStatus::Active]);
        Organization::factory()->create(['name' => 'Beta Restaurant', 'status' => OrganizationStatus::Suspended]);
        Subscription::factory()->for($active)->for($plan)->create(['status' => SubscriptionStatus::Trialing]);

        $this->asUser($admin)->getJson('/api/v1/platform/organizations?search=alpha&status=active&subscription_status=trialing&page=1&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'organizations')
            ->assertJsonPath('organizations.0.id', $active->id)
            ->assertJsonPath('pagination.per_page', 10)
            ->assertJsonPath('filters.search', 'alpha');

        $this->getJson('/api/v1/platform/organizations?status=invalid&per_page=500')
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_platform_admin_can_create_and_inspect_an_organization_without_tenant_membership(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $response = $this->asUser($admin)->postJson('/api/v1/platform/organizations', [
            'name' => 'New Customer', 'slug' => 'new-customer',
        ])->assertCreated()->assertJsonPath('organization.status', 'active');
        $organizationId = $response->json('organization.id');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'organization.created', 'actor_id' => $admin->id, 'organization_id' => $organizationId,
        ]);
        $this->getJson('/api/v1/platform/organizations/'.$organizationId)
            ->assertOk()
            ->assertJsonStructure(['platform_organization' => [
                'organization', 'locations', 'members', 'invitations', 'subscription_status', 'available_plans', 'audit_activity',
            ]]);
    }

    public function test_organization_status_changes_require_a_reason_are_audited_and_do_not_delete_data(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $membership = $this->membership($owner, $organization, Role::OrganizationOwner);
        $location = RestaurantLocation::factory()->for($organization)->create();

        $this->asUser($admin)->patchJson("/api/v1/platform/organizations/{$organization->id}/status", [
            'status' => 'suspended',
        ])->assertUnprocessable();
        $this->patchJson("/api/v1/platform/organizations/{$organization->id}/status", [
            'status' => 'suspended', 'reason' => 'Security review requested.',
        ])->assertOk()->assertJsonPath('organization_status.status', 'suspended');

        $this->asUser($owner)->getJson("/api/v1/organizations/{$organization->id}/context")
            ->assertStatus(423)->assertJsonPath('error.code', 'organization_suspended');
        $this->assertDatabaseHas('organization_memberships', ['id' => $membership->id]);
        $this->assertDatabaseHas('restaurant_locations', ['id' => $location->id]);
        $audit = AuditLog::query()->where('action', 'organization.status_changed')->firstOrFail();
        $this->assertSame('Security review requested.', $audit->metadata['reason']);
    }

    public function test_subscription_high_risk_actions_require_and_audit_a_reason(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->for($organization)->for($plan)->create();

        $this->asUser($admin)->patchJson("/api/v1/platform/organizations/{$organization->id}/subscription", [
            'action' => 'suspend',
        ])->assertUnprocessable();
        $this->patchJson("/api/v1/platform/organizations/{$organization->id}/subscription", [
            'action' => 'suspend', 'reason' => 'Account review.',
        ])->assertOk()->assertJsonPath('subscription_status.subscription.status', 'suspended');
        $audit = AuditLog::query()->where('action', 'subscription.suspend')->firstOrFail();
        $this->assertSame('Account review.', $audit->metadata['reason']);
    }

    public function test_initial_owner_invitation_and_resend_replace_the_active_token_without_persisting_plaintext(): void
    {
        Notification::fake();
        $admin = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->create();

        $first = $this->asUser($admin)->postJson("/api/v1/platform/organizations/{$organization->id}/initial-owner-invitations", [
            'email' => 'Owner@Example.test',
        ])->assertCreated()->assertJsonPath('invitation.intended_role', 'organization_owner');
        $firstId = $first->json('invitation.id');
        $firstHash = Invitation::query()->findOrFail($firstId)->token_hash;

        $second = $this->postJson("/api/v1/platform/organizations/{$organization->id}/initial-owner-invitations/{$firstId}/resend")
            ->assertOk()->assertJsonPath('invitation.intended_email', 'owner@example.test');
        $secondId = $second->json('invitation.id');

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(InvitationStatus::Revoked, Invitation::query()->findOrFail($firstId)->status);
        $this->assertSame(InvitationStatus::Pending, Invitation::query()->findOrFail($secondId)->status);
        $this->assertSame(1, Invitation::query()->where('organization_id', $organization->id)->where('status', InvitationStatus::Pending)->count());
        $this->assertNotSame($firstHash, Invitation::query()->findOrFail($secondId)->token_hash);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invitation.initial_owner_created', 'subject_id' => $firstId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invitation.initial_owner_replaced', 'subject_id' => $secondId]);
        Notification::assertSentOnDemand(OrganizationOwnerInvitation::class, 2);

        foreach (AuditLog::query()->get() as $audit) {
            $this->assertStringNotContainsString($firstHash, json_encode($audit->metadata, JSON_THROW_ON_ERROR));
        }
    }

    public function test_resend_rejects_an_invitation_from_another_organization(): void
    {
        Notification::fake();
        $admin = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $invitation = Invitation::factory()->for($other)->create(['intended_role' => Role::OrganizationOwner]);

        $this->asUser($admin)->postJson("/api/v1/platform/organizations/{$organization->id}/initial-owner-invitations/{$invitation->id}/resend")
            ->assertNotFound()->assertJsonPath('error.code', 'invitation_unavailable');
    }
}
