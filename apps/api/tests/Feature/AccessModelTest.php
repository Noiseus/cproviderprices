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
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\RestaurantLocation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AccessModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_have_memberships_in_two_organizations(): void
    {
        $user = User::factory()->create();
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        OrganizationMembership::factory()->for($user)->for($organizationA)->create();
        OrganizationMembership::factory()->for($user)->for($organizationB)->create([
            'role' => Role::Viewer,
        ]);

        $this->assertCount(2, $user->memberships()->get());
        $this->assertEqualsCanonicalizing(
            [$organizationA->id, $organizationB->id],
            $user->memberships()->pluck('organization_id')->all(),
        );
    }

    public function test_duplicate_organization_membership_is_rejected(): void
    {
        $membership = OrganizationMembership::factory()->create();

        $this->expectException(QueryException::class);

        OrganizationMembership::factory()->create([
            'organization_id' => $membership->organization_id,
            'user_id' => $membership->user_id,
        ]);
    }

    /** @param array{role: Role, status: MembershipStatus} $attributes */
    #[DataProvider('validMembershipStates')]
    public function test_membership_role_and_status_values_are_typed(array $attributes): void
    {
        $attributes['joined_at'] = $attributes['status'] === MembershipStatus::Invited ? null : now();
        $membership = OrganizationMembership::factory()->create($attributes);

        $this->assertSame($attributes['role'], $membership->role);
        $this->assertSame($attributes['status'], $membership->status);
    }

    /** @return iterable<string, array{array{role: Role, status: MembershipStatus}}> */
    public static function validMembershipStates(): iterable
    {
        yield 'invited owner' => [[
            'role' => Role::OrganizationOwner,
            'status' => MembershipStatus::Invited,
        ]];
        yield 'active buyer' => [[
            'role' => Role::Buyer,
            'status' => MembershipStatus::Active,
        ]];
        yield 'suspended viewer' => [[
            'role' => Role::Viewer,
            'status' => MembershipStatus::Suspended,
        ]];
    }

    public function test_platform_admin_is_not_an_organization_membership_role(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->platformAdmin()->create();
        $now = now();

        $this->expectException(QueryException::class);

        DB::table('organization_memberships')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => Role::PlatformAdmin->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_invalid_membership_status_is_rejected_by_the_database(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $now = now();

        $this->expectException(QueryException::class);

        DB::table('organization_memberships')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => Role::Buyer->value,
            'status' => 'unknown',
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_invitations_persist_only_a_hash_of_the_plaintext_token(): void
    {
        $plaintextToken = 'one-time-secret-invitation-token';
        $invitation = Invitation::factory()->withPlaintextToken($plaintextToken)->create();
        $persisted = DB::table('invitations')->where('id', $invitation->id)->first();

        $this->assertNotNull($persisted);
        $this->assertSame(Invitation::hashPlaintextToken($plaintextToken), $persisted->token_hash);
        $this->assertNotSame($plaintextToken, $persisted->token_hash);
        $this->assertFalse(Schema::hasColumn('invitations', 'token'));
        $this->assertArrayNotHasKey('token_hash', $invitation->toArray());
    }

    public function test_invitation_expiration_and_terminal_statuses_are_represented(): void
    {
        $overdue = Invitation::factory()->create(['expires_at' => now()->subSecond()]);
        $accepted = Invitation::factory()->create([
            'status' => InvitationStatus::Accepted,
            'accepted_at' => now(),
        ]);
        $revoked = Invitation::factory()->create([
            'status' => InvitationStatus::Revoked,
            'revoked_at' => now(),
        ]);

        $this->assertSame(InvitationStatus::Expired, $overdue->lifecycleStatus());
        $this->assertFalse($overdue->isActive());
        $this->assertSame(InvitationStatus::Accepted, $accepted->lifecycleStatus());
        $this->assertSame(InvitationStatus::Revoked, $revoked->lifecycleStatus());
    }

    public function test_only_one_pending_invitation_exists_per_normalized_email_and_organization(): void
    {
        $organization = Organization::factory()->create();
        Invitation::factory()->for($organization)->create([
            'intended_email' => 'buyer@example.com',
        ]);

        $this->expectException(QueryException::class);

        Invitation::factory()->for($organization)->create([
            'intended_email' => '  BUYER@EXAMPLE.COM ',
        ]);
    }

    public function test_invitation_token_hash_is_unique(): void
    {
        $token = 'same-transient-token';
        Invitation::factory()->withPlaintextToken($token)->create();

        $this->expectException(QueryException::class);

        Invitation::factory()->withPlaintextToken($token)->create();
    }

    public function test_user_email_is_normalized_and_unique(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);

        $this->expectException(QueryException::class);

        User::factory()->create(['email' => '  OWNER@EXAMPLE.COM ']);
    }

    public function test_plan_code_is_unique(): void
    {
        Plan::factory()->create(['code' => 'starter']);

        $this->expectException(QueryException::class);

        Plan::factory()->create(['code' => 'starter']);
    }

    public function test_plan_codes_and_entitlement_keys_are_unique_in_their_scopes(): void
    {
        $plan = Plan::factory()->create(['code' => 'starter']);
        PlanEntitlement::factory()->for($plan)->create(['key' => 'locations.maximum']);

        $this->expectException(QueryException::class);

        PlanEntitlement::factory()->for($plan)->create(['key' => 'locations.maximum']);
    }

    public function test_organization_and_subscription_lifecycles_are_independent(): void
    {
        $organization = Organization::factory()->create([
            'status' => OrganizationStatus::Active,
        ]);
        $subscription = Subscription::factory()->for($organization)->create();

        $subscription->update([
            'status' => SubscriptionStatus::Canceled,
            'canceled_at' => now(),
            'ends_at' => now(),
        ]);

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->refresh()->status);
        $this->assertSame(OrganizationStatus::Active, $organization->refresh()->status);
    }

    public function test_restaurant_locations_are_organization_owned_and_codes_are_tenant_scoped(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $locationA = RestaurantLocation::factory()->for($organizationA)->create(['code' => 'DOWNTOWN']);
        $locationB = RestaurantLocation::factory()->for($organizationB)->create(['code' => 'DOWNTOWN']);

        $this->assertTrue($locationA->organization->is($organizationA));
        $this->assertTrue($locationB->organization->is($organizationB));
        $this->assertSame(1, $organizationA->restaurantLocations()->count());
        $this->assertSame(1, $organizationB->restaurantLocations()->count());
    }

    public function test_restaurant_location_code_is_unique_within_an_organization(): void
    {
        $organization = Organization::factory()->create();
        RestaurantLocation::factory()->for($organization)->create(['code' => 'DOWNTOWN']);

        $this->expectException(QueryException::class);

        RestaurantLocation::factory()->for($organization)->create(['code' => 'DOWNTOWN']);
    }

    public function test_factories_create_a_realistic_multi_organization_membership_scenario(): void
    {
        $user = User::factory()->create();
        $organizations = Organization::factory()->count(2)->create();

        foreach ($organizations as $organization) {
            OrganizationMembership::factory()->for($user)->for($organization)->create();
        }

        $this->assertSame(2, OrganizationMembership::query()->whereBelongsTo($user)->count());
        $this->assertSame(2, OrganizationMembership::query()->distinct()->count('organization_id'));
    }

    public function test_audit_logs_preserve_context_without_existing_mutable_relationships(): void
    {
        $organizationId = (string) Str::uuid7();
        $actorId = (string) Str::uuid7();
        $subjectId = (string) Str::uuid7();
        $auditLog = AuditLog::factory()->create([
            'organization_id' => $organizationId,
            'actor_id' => $actorId,
            'actor_type' => 'user',
            'actor_label' => 'buyer@example.com',
            'action' => 'membership.role_changed',
            'subject_type' => 'organization_membership',
            'subject_id' => $subjectId,
            'metadata' => ['from' => 'viewer', 'to' => 'buyer'],
        ]);

        $this->assertSame($organizationId, $auditLog->organization_id);
        $this->assertSame($actorId, $auditLog->actor_id);
        $this->assertSame($subjectId, $auditLog->subject_id);
        $this->assertSame(['from' => 'viewer', 'to' => 'buyer'], $auditLog->metadata);

        $this->expectException(LogicException::class);
        $auditLog->update(['action' => 'changed']);
    }

    public function test_domain_factories_generate_uuid_version_seven_identifiers(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Str::isUuid($user->id));
        $this->assertSame('7', $user->id[14]);
    }
}
