<?php

namespace Tests\Feature;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\RestaurantLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenantContext;
use Tests\TestCase;

final class TenantAuthorizationTest extends TestCase
{
    use BuildsTenantContext;
    use RefreshDatabase;

    public function test_changing_route_organization_uuid_cannot_cross_tenant_boundary(): void
    {
        $user = User::factory()->create();
        $allowed = Organization::factory()->create();
        $denied = Organization::factory()->create();
        $this->membership($user, $allowed);

        $this->asUser($user)->getJson("/api/v1/organizations/{$allowed->id}/context")->assertOk();
        $this->getJson("/api/v1/organizations/{$denied->id}/context")
            ->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    }

    public function test_direct_location_id_is_scoped_to_route_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $this->membership($user, $organization);
        $otherLocation = RestaurantLocation::factory()->for($other)->create();

        $this->asUser($user)->getJson("/api/v1/organizations/{$organization->id}/restaurant-locations/{$otherLocation->id}")
            ->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_viewer_is_read_only_and_denied_high_risk_actions_are_audited(): void
    {
        $viewer = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($viewer, $organization, Role::Viewer);
        $location = RestaurantLocation::factory()->for($organization)->create();

        $this->asUser($viewer)->getJson("/api/v1/organizations/{$organization->id}/restaurant-locations/{$location->id}")->assertOk();
        $this->patchJson("/api/v1/organizations/{$organization->id}/restaurant-locations/{$location->id}", ['name' => 'Denied'])
            ->assertForbidden();
        $this->deleteJson("/api/v1/organizations/{$organization->id}/restaurant-locations/{$location->id}")
            ->assertForbidden();
        $this->assertSame(2, AuditLog::query()->where('action', 'authorization.high_risk_denied')->count());
        $this->assertDatabaseHas('restaurant_locations', ['id' => $location->id]);
    }

    public function test_buyer_cannot_perform_owner_only_role_changes(): void
    {
        $buyer = User::factory()->create();
        $targetUser = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($buyer, $organization, Role::Buyer);
        $target = $this->membership($targetUser, $organization, Role::Viewer);

        $this->asUser($buyer)->patchJson("/api/v1/organizations/{$organization->id}/memberships/{$target->id}", [
            'role' => Role::Buyer->value,
        ])->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'authorization.high_risk_denied', 'subject_id' => $target->id]);
    }

    public function test_owner_role_change_uses_authoritative_route_organization_and_is_audited(): void
    {
        $owner = User::factory()->create();
        $targetUser = User::factory()->create();
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $this->membership($owner, $organization, Role::OrganizationOwner);
        $target = $this->membership($targetUser, $organization, Role::Viewer);

        $this->asUser($owner)->patchJson("/api/v1/organizations/{$organization->id}/memberships/{$target->id}", [
            'role' => Role::Buyer->value, 'organization_id' => $other->id,
        ])->assertOk()->assertJsonPath('membership.role', Role::Buyer->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership.role_changed', 'organization_id' => $organization->id]);
    }

    public function test_owner_cannot_use_platform_scope_and_denial_is_audited(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($owner, $organization, Role::OrganizationOwner);

        $this->asUser($owner)->getJson('/api/v1/platform/context')
            ->assertForbidden()->assertJsonPath('error.code', 'platform_scope_required');
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.access_denied', 'actor_id' => $owner->id]);
    }

    public function test_platform_admin_requires_explicit_platform_route_and_actions_are_audited(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->create();

        $this->asUser($admin)->getJson("/api/v1/organizations/{$organization->id}/context")->assertForbidden();
        $this->getJson('/api/v1/platform/context')->assertOk()->assertJsonPath('scope', 'platform');
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.action', 'actor_id' => $admin->id]);
    }

    public function test_suspended_membership_is_denied(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($user, $organization, Role::Buyer, MembershipStatus::Suspended);

        $this->asUser($user)->getJson("/api/v1/organizations/{$organization->id}/context")
            ->assertForbidden()->assertJsonPath('error.code', 'membership_suspended');
    }

    public function test_suspended_organization_receives_only_status_access(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create(['status' => OrganizationStatus::Suspended]);
        $this->membership($user, $organization);

        $this->asUser($user)->getJson("/api/v1/organizations/{$organization->id}/context")
            ->assertStatus(423)->assertJsonPath('error.code', 'organization_suspended');
        $this->getJson("/api/v1/organizations/{$organization->id}/status")
            ->assertOk()->assertJsonPath('organization_status.normal_access_available', false);
    }

    public function test_switching_requires_multiple_active_memberships_and_scopes_destination(): void
    {
        $user = User::factory()->create();
        $first = Organization::factory()->create();
        $second = Organization::factory()->create(['slug' => 'second-org']);
        $this->membership($user, $first);
        $this->membership($user, $second);

        $this->asUser($user)->postJson("/api/v1/organizations/{$second->id}/switch", [
            'intended_destination' => 'https://attacker.example/',
        ])->assertOk()->assertJsonPath('destination', '/o/second-org/dashboard');
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.switched', 'organization_id' => $second->id]);

        OrganizationMembership::query()->where('organization_id', $first->id)->update(['status' => MembershipStatus::Left]);
        $this->postJson("/api/v1/organizations/{$second->id}/switch", [])
            ->assertUnprocessable()->assertJsonPath('error.code', 'organization_switch_unavailable');
    }
}
