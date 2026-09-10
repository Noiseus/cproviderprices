<?php

namespace Tests\Feature;

use App\Enums\EntitlementKey;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationStatus;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\RestaurantLocation;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntitlementService;
use App\Support\ApiProblem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenantContext;
use Tests\TestCase;

final class EntitlementSubscriptionTest extends TestCase
{
    use BuildsTenantContext;
    use RefreshDatabase;

    public function test_platform_admin_starts_and_extends_a_deterministic_trial(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->create();
        $plan = $this->plan();
        $trialEnd = now()->addDays(14)->startOfSecond();

        $this->asUser($admin)->patchJson("/api/v1/platform/organizations/{$organization->id}/subscription", [
            'action' => 'start_trial', 'plan_code' => $plan->code, 'trial_ends_at' => $trialEnd->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('subscription_status.subscription.status', SubscriptionStatus::Trialing->value)
            ->assertJsonPath('subscription_status.subscription.trial_ends_at', $trialEnd->toIso8601String());

        $extendedEnd = $trialEnd->copy()->addWeek();
        $this->patchJson("/api/v1/platform/organizations/{$organization->id}/subscription", [
            'action' => 'extend_trial', 'trial_ends_at' => $extendedEnd->toIso8601String(),
        ])->assertOk()->assertJsonPath('subscription_status.subscription.trial_ends_at', $extendedEnd->toIso8601String());
    }

    public function test_trial_expiration_changes_entitlement_access_without_mutating_the_recorded_status(): void
    {
        Carbon::setTestNow('2026-09-09T12:00:00Z');
        $organization = Organization::factory()->create();
        $plan = $this->plan([EntitlementKey::ManualImportsEnabled->value => ['enabled' => true]]);
        $subscription = Subscription::factory()->for($organization)->for($plan)->create([
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addMinute(),
        ]);
        $service = app(EntitlementService::class);
        $this->assertTrue($service->featureEnabled($organization, EntitlementKey::ManualImportsEnabled));

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertFalse($service->featureEnabled($organization, EntitlementKey::ManualImportsEnabled));
        $this->assertSame(SubscriptionStatus::Expired, $subscription->refresh()->lifecycleStatus());
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        Carbon::setTestNow();
    }

    public function test_enabled_feature_is_allowed_and_disabled_feature_is_denied(): void
    {
        $organization = Organization::factory()->create();
        $plan = $this->plan([
            EntitlementKey::ManualImportsEnabled->value => ['enabled' => true],
            EntitlementKey::AutomaticSyncEnabled->value => ['enabled' => false],
        ]);
        Subscription::factory()->for($organization)->for($plan)->create();
        $service = app(EntitlementService::class);

        $service->assertFeatureEnabled($organization, EntitlementKey::ManualImportsEnabled);
        $this->expectExceptionObject(new ApiProblem(
            'feature_disabled', 'This feature is not available for the current plan.', 403,
            ['entitlement_key' => EntitlementKey::AutomaticSyncEnabled->value],
        ));
        $service->assertFeatureEnabled($organization, EntitlementKey::AutomaticSyncEnabled);
    }

    public function test_location_limit_allows_below_maximum_and_returns_structured_conflict_at_maximum(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($owner, $organization, Role::OrganizationOwner);
        $plan = $this->plan([EntitlementKey::MaxLocations->value => ['limit' => 1]]);
        Subscription::factory()->for($organization)->for($plan)->create();

        $this->asUser($owner)->postJson("/api/v1/organizations/{$organization->id}/restaurant-locations", [
            'name' => 'Downtown', 'code' => 'DOWNTOWN', 'timezone' => 'America/New_York',
        ])->assertCreated();
        $this->postJson("/api/v1/organizations/{$organization->id}/restaurant-locations", [
            'name' => 'Uptown', 'code' => 'UPTOWN', 'timezone' => 'America/New_York',
        ])->assertConflict()
            ->assertJsonPath('error.code', 'limit_reached')
            ->assertJsonPath('error.details.entitlement_key', EntitlementKey::MaxLocations->value)
            ->assertJsonPath('error.details.limit', 1)
            ->assertJsonPath('error.details.current_usage', 1)
            ->assertJsonPath('error.request_id', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_status_response_exposes_safe_usage_and_future_supplier_usage_is_unimplemented(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($user, $organization);
        RestaurantLocation::factory()->for($organization)->create();
        $plan = $this->plan([
            EntitlementKey::MaxLocations->value => ['limit' => 3],
            EntitlementKey::MaxSupplierAccounts->value => ['limit' => 2],
        ]);
        Subscription::factory()->for($organization)->for($plan)->create();

        $this->asUser($user)->getJson("/api/v1/organizations/{$organization->id}/subscription")
            ->assertOk()
            ->assertJsonPath('subscription_status.limits.max_locations.limit', 3)
            ->assertJsonPath('subscription_status.limits.max_locations.current_usage', 1)
            ->assertJsonPath('subscription_status.limits.max_locations.reached', false)
            ->assertJsonPath('subscription_status.limits.max_supplier_accounts.current_usage', null);
    }

    public function test_user_limit_is_enforced_when_an_invited_membership_would_become_active(): void
    {
        $organization = Organization::factory()->create();
        $existingUser = User::factory()->create();
        $this->membership($existingUser, $organization);
        $plan = $this->plan([EntitlementKey::MaxUsers->value => ['limit' => 1]]);
        Subscription::factory()->for($organization)->for($plan)->create();
        $token = Str::random(64);
        $password = Str::password(24);
        $invitation = Invitation::factory()->for($organization)->withPlaintextToken($token)->create();

        $this->postJson('/api/v1/auth/invitations/accept', [
            'token' => $token,
            'name' => 'Limited Invitee',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertConflict()
            ->assertJsonPath('error.code', 'limit_reached')
            ->assertJsonPath('error.details.entitlement_key', 'max_users')
            ->assertJsonPath('error.details.limit', 1)
            ->assertJsonPath('error.details.current_usage', 1);

        $this->assertSame(InvitationStatus::Pending, $invitation->refresh()->status);
        $this->assertDatabaseMissing('users', ['email' => $invitation->intended_email]);
    }

    public function test_plan_change_affects_entitlement_authorization_on_the_next_request(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($owner, $organization, Role::OrganizationOwner);
        $small = $this->plan([EntitlementKey::MaxLocations->value => ['limit' => 0]]);
        $large = $this->plan([EntitlementKey::MaxLocations->value => ['limit' => 2]]);
        Subscription::factory()->for($organization)->for($small)->create();

        $this->asUser($owner)->postJson("/api/v1/organizations/{$organization->id}/restaurant-locations", [
            'name' => 'Denied', 'code' => 'DENIED', 'timezone' => 'UTC',
        ])->assertConflict();
        $this->asUser($admin)->patchJson("/api/v1/platform/organizations/{$organization->id}/subscription", [
            'action' => 'activate', 'plan_code' => $large->code,
        ])->assertOk();
        $this->asUser($owner)->postJson("/api/v1/organizations/{$organization->id}/restaurant-locations", [
            'name' => 'Allowed', 'code' => 'ALLOWED', 'timezone' => 'UTC',
        ])->assertCreated();
    }

    public function test_suspension_prevents_gated_actions_without_erasing_tenant_data_or_changing_organization_lifecycle(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['status' => OrganizationStatus::Active]);
        $membership = $this->membership($owner, $organization, Role::OrganizationOwner);
        $location = RestaurantLocation::factory()->for($organization)->create();
        $plan = $this->plan([EntitlementKey::MaxLocations->value => ['limit' => 3]]);
        Subscription::factory()->for($organization)->for($plan)->create();

        $this->asUser($admin)->patchJson("/api/v1/platform/organizations/{$organization->id}/subscription", [
            'action' => 'suspend',
        ])->assertOk()->assertJsonPath('subscription_status.subscription.status', 'suspended');
        $this->asUser($owner)->postJson("/api/v1/organizations/{$organization->id}/restaurant-locations", [
            'name' => 'Blocked', 'code' => 'BLOCKED', 'timezone' => 'UTC',
        ])->assertStatus(423)->assertJsonPath('error.code', 'subscription_suspended');

        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'status' => OrganizationStatus::Active->value]);
        $this->assertDatabaseHas('organization_memberships', ['id' => $membership->id]);
        $this->assertDatabaseHas('restaurant_locations', ['id' => $location->id]);
    }

    public function test_owner_cannot_administer_subscriptions_and_platform_admin_actions_are_audited(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->create();
        $this->membership($owner, $organization, Role::OrganizationOwner);
        $plan = $this->plan();

        $this->asUser($owner)->patchJson("/api/v1/platform/organizations/{$organization->id}/subscription", [
            'action' => 'start_trial', 'plan_code' => $plan->code, 'trial_ends_at' => now()->addWeek()->toIso8601String(),
        ])->assertForbidden()->assertJsonPath('error.code', 'platform_scope_required');
        $this->asUser($admin)->patchJson("/api/v1/platform/organizations/{$organization->id}/subscription", [
            'action' => 'start_trial', 'plan_code' => $plan->code, 'trial_ends_at' => now()->addWeek()->toIso8601String(),
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.access_denied', 'actor_id' => $owner->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.start_trial', 'actor_id' => $admin->id]);
        $this->assertGreaterThanOrEqual(1, AuditLog::query()->where('action', 'platform.action')->where('actor_id', $admin->id)->count());
    }

    public function test_entitlement_check_cannot_bypass_membership_role_authorization(): void
    {
        $viewer = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($viewer, $organization, Role::Viewer);
        $plan = $this->plan([EntitlementKey::MaxLocations->value => ['limit' => 0]]);
        Subscription::factory()->for($organization)->for($plan)->create();

        $response = $this->asUser($viewer)->postJson("/api/v1/organizations/{$organization->id}/restaurant-locations", [
            'name' => 'Hidden limit', 'code' => 'HIDDEN', 'timezone' => 'UTC',
        ])->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $response->assertJsonMissing(['entitlement_key' => EntitlementKey::MaxLocations->value]);
    }

    /** @param array<string, array<string, bool|int>> $overrides */
    private function plan(array $overrides = []): Plan
    {
        $plan = Plan::factory()->create();
        $values = [
            EntitlementKey::MaxLocations->value => ['limit' => 5],
            EntitlementKey::MaxUsers->value => ['limit' => 5],
            EntitlementKey::MaxSupplierAccounts->value => ['limit' => 1],
            EntitlementKey::ManualImportsEnabled->value => ['enabled' => false],
            EntitlementKey::AutomaticSyncEnabled->value => ['enabled' => false],
            EntitlementKey::PriceHistoryEnabled->value => ['enabled' => false],
            EntitlementKey::BasketOptimizerEnabled->value => ['enabled' => false],
            ...$overrides,
        ];

        foreach ($values as $key => $value) {
            PlanEntitlement::factory()->for($plan)->create(['key' => $key, 'value' => $value]);
        }

        return $plan;
    }
}
