<?php

namespace Tests\Feature;

use App\Enums\EntitlementKey;
use App\Enums\InvitationStatus;
use App\Enums\LocationStatus;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\RestaurantLocation;
use App\Models\Subscription;
use App\Models\SupplierAccount;
use App\Models\SupplierDefinition;
use App\Models\User;
use Database\Seeders\SupplierDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTenantContext;
use Tests\TestCase;

final class CustomerConfigurationTest extends TestCase
{
    use BuildsTenantContext;
    use RefreshDatabase;

    public function test_owner_can_list_team_and_invite_buyer_and_viewer(): void
    {
        Notification::fake();
        [$owner, $organization] = $this->ownerContext();
        $this->asUser($owner)->getJson($this->url($organization, 'team'))
            ->assertOk()->assertJsonCount(1, 'team.members');

        foreach ([Role::Buyer, Role::Viewer] as $role) {
            $this->postJson($this->url($organization, 'invitations'), [
                'email' => $role->value.'@example.test', 'role' => $role->value,
            ])->assertCreated()->assertJsonPath('invitation.intended_role', $role->value);
        }
        $this->assertDatabaseCount('invitations', 2);
    }

    public function test_owner_invitation_requires_confirmation_and_resend_and_revoke_are_safe(): void
    {
        Notification::fake();
        [$owner, $organization] = $this->ownerContext();
        $url = $this->url($organization, 'invitations');
        $this->asUser($owner)->postJson($url, ['email' => 'owner2@example.test', 'role' => Role::OrganizationOwner->value])
            ->assertUnprocessable()->assertJsonPath('error.code', 'high_risk_confirmation_required');
        $invitationId = $this->postJson($url, [
            'email' => 'owner2@example.test', 'role' => Role::OrganizationOwner->value, 'high_risk_confirmed' => true,
        ])->assertCreated()->json('invitation.id');
        $replacementId = $this->postJson($url.'/'.$invitationId.'/resend')->assertOk()->json('invitation.id');
        $this->assertNotSame($invitationId, $replacementId);
        $this->assertDatabaseHas('invitations', ['id' => $invitationId, 'status' => InvitationStatus::Revoked->value]);
        $this->deleteJson($url.'/'.$replacementId)->assertOk();
        $this->assertDatabaseHas('invitations', ['id' => $replacementId, 'status' => InvitationStatus::Revoked->value]);
    }

    public function test_buyer_and_viewer_can_view_team_but_cannot_administer_it(): void
    {
        Notification::fake();
        [, $organization] = $this->ownerContext();
        foreach ([Role::Buyer, Role::Viewer] as $role) {
            $user = User::factory()->create();
            $this->membership($user, $organization, $role);
            $this->asUser($user)->getJson($this->url($organization, 'team'))->assertOk();
            $this->postJson($this->url($organization, 'invitations'), [
                'email' => fake()->unique()->safeEmail(), 'role' => Role::Viewer->value,
            ])->assertForbidden();
        }
    }

    public function test_last_active_owner_cannot_be_downgraded_or_revoked_and_cross_tenant_target_is_hidden(): void
    {
        [$owner, $organization, $membership] = $this->ownerContext();
        $this->asUser($owner)->patchJson($this->url($organization, 'memberships/'.$membership->id), ['role' => Role::Buyer->value])
            ->assertConflict()->assertJsonPath('error.code', 'last_owner_required');
        $this->deleteJson($this->url($organization, 'memberships/'.$membership->id))
            ->assertConflict()->assertJsonPath('error.code', 'last_owner_required');

        $other = Organization::factory()->create();
        $otherMembership = $this->membership(User::factory()->create(), $other, Role::Viewer);
        $this->patchJson($this->url($organization, 'memberships/'.$otherMembership->id), ['role' => Role::Buyer->value])->assertNotFound();
    }

    public function test_owner_can_create_edit_select_and_deactivate_location_without_client_tenant_override(): void
    {
        [$owner, $organization] = $this->ownerContext();
        $this->subscription($organization, [EntitlementKey::MaxLocations->value => ['limit' => 2]]);
        $other = Organization::factory()->create();
        $response = $this->asUser($owner)->postJson($this->url($organization, 'restaurant-locations'), [
            'organization_id' => $other->id, 'name' => 'Downtown', 'address_line_1' => '1 Main St', 'city' => 'Raleigh',
            'region' => 'NC', 'postal_code' => '27601', 'timezone' => 'America/New_York', 'external_reference' => 'POS-1',
        ])->assertCreated()->assertJsonPath('restaurant_location.city', 'Raleigh');
        $id = $response->json('restaurant_location.id');
        $this->assertDatabaseHas('restaurant_locations', ['id' => $id, 'organization_id' => $organization->id]);
        $this->patchJson($this->url($organization, 'restaurant-locations/'.$id), ['name' => 'Downtown Kitchen'])->assertOk();
        $this->postJson($this->url($organization, 'restaurant-locations/'.$id.'/select'))->assertOk()->assertJsonPath('selected_location_id', $id);
        $this->deleteJson($this->url($organization, 'restaurant-locations/'.$id))->assertOk();
        $this->assertDatabaseHas('restaurant_locations', ['id' => $id, 'status' => LocationStatus::Inactive->value]);
    }

    public function test_location_limit_and_cross_tenant_location_selection_are_enforced(): void
    {
        [$owner, $organization] = $this->ownerContext();
        $this->subscription($organization, [EntitlementKey::MaxLocations->value => ['limit' => 0]]);
        $this->asUser($owner)->postJson($this->url($organization, 'restaurant-locations'), [
            'name' => 'Blocked', 'timezone' => 'UTC',
        ])->assertConflict()->assertJsonPath('error.code', 'limit_reached');
        $otherLocation = RestaurantLocation::factory()->create();
        $this->postJson($this->url($organization, 'restaurant-locations/'.$otherLocation->id.'/select'))->assertNotFound();
    }

    public function test_location_permissions_are_read_only_for_buyer_and_viewer(): void
    {
        [, $organization] = $this->ownerContext();
        $location = RestaurantLocation::factory()->for($organization)->create();
        foreach ([Role::Buyer, Role::Viewer] as $role) {
            $user = User::factory()->create();
            $this->membership($user, $organization, $role);
            $this->asUser($user)->getJson($this->url($organization, 'restaurant-locations'))->assertOk();
            $this->patchJson($this->url($organization, 'restaurant-locations/'.$location->id), ['name' => 'Denied'])->assertForbidden();
        }
    }

    public function test_stable_supplier_definitions_are_idempotently_seeded(): void
    {
        $this->seed(SupplierDefinitionSeeder::class);
        $this->seed(SupplierDefinitionSeeder::class);
        $this->assertSame(6, SupplierDefinition::query()->count());
        $this->assertSame(['freshpoint', 'performance-foodservice', 'prestige', 'sysco', 'us-foods', 'vistar'],
            SupplierDefinition::query()->orderBy('code')->pluck('code')->all());
    }

    public function test_owner_can_create_and_edit_safe_supplier_metadata_for_own_location(): void
    {
        [$owner, $organization] = $this->ownerContext();
        $this->subscription($organization, [EntitlementKey::MaxSupplierAccounts->value => ['limit' => 2]]);
        $location = RestaurantLocation::factory()->for($organization)->create();
        $supplier = SupplierDefinition::factory()->create();
        $response = $this->asUser($owner)->postJson($this->url($organization, 'supplier-accounts'), $this->supplierPayload($location, $supplier))
            ->assertCreated()->assertJsonPath('supplier_account.last_successful_sync_at', null);
        $id = $response->json('supplier_account.id');
        $this->patchJson($this->url($organization, 'supplier-accounts/'.$id), [
            'display_name' => 'Primary account', 'status' => 'inactive',
        ])->assertOk()->assertJsonPath('supplier_account.status', 'inactive');
        $this->assertDatabaseHas('audit_logs', ['action' => 'supplier_account.status_changed', 'subject_id' => $id]);
    }

    public function test_supplier_limit_duplicate_credentials_and_plan_changes_are_enforced(): void
    {
        [$owner, $organization] = $this->ownerContext();
        $this->subscription($organization, [EntitlementKey::MaxSupplierAccounts->value => ['limit' => 1]]);
        $location = RestaurantLocation::factory()->for($organization)->create();
        $supplier = SupplierDefinition::factory()->create();
        $payload = $this->supplierPayload($location, $supplier);
        $this->asUser($owner)->postJson($this->url($organization, 'supplier-accounts'), $payload)->assertCreated();
        $this->postJson($this->url($organization, 'supplier-accounts'), $payload)->assertConflict();
        $otherSupplier = SupplierDefinition::factory()->create();
        $this->postJson($this->url($organization, 'supplier-accounts'), $this->supplierPayload($location, $otherSupplier))
            ->assertConflict()->assertJsonPath('error.code', 'limit_reached');
        $this->postJson($this->url($organization, 'supplier-accounts'), [
            ...$this->supplierPayload($location, $otherSupplier), 'portal_password' => 'never-store-this',
        ])->assertUnprocessable();

        $large = $this->plan([EntitlementKey::MaxSupplierAccounts->value => ['limit' => 3]]);
        $admin = User::factory()->platformAdmin()->create();
        $this->asUser($admin)->patchJson("/api/v1/platform/organizations/{$organization->id}/subscription", [
            'action' => 'activate', 'plan_code' => $large->code,
        ])->assertOk();
        $this->asUser($owner)->postJson($this->url($organization, 'supplier-accounts'), $this->supplierPayload($location, $otherSupplier))->assertCreated();
    }

    public function test_supplier_account_tenant_boundaries_and_read_only_roles_are_enforced(): void
    {
        [$owner, $organization] = $this->ownerContext();
        $this->subscription($organization, [EntitlementKey::MaxSupplierAccounts->value => ['limit' => 3]]);
        $location = RestaurantLocation::factory()->for($organization)->create();
        $otherLocation = RestaurantLocation::factory()->create();
        $supplier = SupplierDefinition::factory()->create();
        $this->asUser($owner)->postJson($this->url($organization, 'supplier-accounts'), $this->supplierPayload($otherLocation, $supplier))->assertNotFound();

        $otherAccount = SupplierAccount::factory()->create();
        $this->patchJson($this->url($organization, 'supplier-accounts/'.$otherAccount->id), ['display_name' => 'Tampered'])->assertNotFound();
        foreach ([Role::Buyer, Role::Viewer] as $role) {
            $user = User::factory()->create();
            $this->membership($user, $organization, $role);
            $this->asUser($user)->getJson($this->url($organization, 'supplier-accounts'))->assertOk();
            $this->postJson($this->url($organization, 'supplier-accounts'), $this->supplierPayload($location, $supplier))->assertForbidden();
        }
    }

    /** @return array{User, Organization, OrganizationMembership} */
    private function ownerContext(): array
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();

        return [$owner, $organization, $this->membership($owner, $organization, Role::OrganizationOwner)];
    }

    /** @param array<string, array<string, int|bool>> $overrides */
    private function plan(array $overrides): Plan
    {
        $plan = Plan::factory()->create();
        foreach ([
            EntitlementKey::MaxLocations->value => ['limit' => 5],
            EntitlementKey::MaxUsers->value => ['limit' => 5],
            EntitlementKey::MaxSupplierAccounts->value => ['limit' => 5],
            ...$overrides,
        ] as $key => $value) {
            PlanEntitlement::factory()->for($plan)->create(['key' => $key, 'value' => $value]);
        }

        return $plan;
    }

    /** @param array<string, array<string, int|bool>> $overrides */
    private function subscription(Organization $organization, array $overrides): Subscription
    {
        return Subscription::factory()->for($organization)->for($this->plan($overrides))->create();
    }

    /** @return array<string, string> */
    private function supplierPayload(RestaurantLocation $location, SupplierDefinition $supplier): array
    {
        return [
            'restaurant_location_id' => $location->id,
            'supplier_definition_id' => $supplier->id,
            'display_name' => 'Main supplier',
            'masked_account_reference' => 'ending 1234',
            'source_method' => 'manual_import',
            'expected_refresh_cadence' => 'weekly',
        ];
    }

    private function url(Organization $organization, string $path): string
    {
        return "/api/v1/organizations/{$organization->id}/{$path}";
    }
}
