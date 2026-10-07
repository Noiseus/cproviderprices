<?php

namespace Tests\Feature;

use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Models\ImportRun;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\RestaurantLocation;
use App\Models\SavedList;
use App\Models\User;
use App\Services\AccessBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;
use Tests\Concerns\BuildsTenantContext;
use Tests\TestCase;

final class LocalDemoAccessCommandTest extends TestCase
{
    use BuildsTenantContext;
    use RefreshDatabase;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);

                continue;
            }

            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        parent::tearDown();
    }

    public function test_local_demo_command_refuses_non_local_environments_before_reading_credentials(): void
    {
        config(['app.env' => 'production']);

        $this->assertSame(Command::FAILURE, Artisan::call('app:bootstrap-local-demo'));
        $this->assertSame(0, User::query()->count());
        $this->assertStringContainsString('restricted to local or development', Artisan::output());
    }

    public function test_local_demo_command_fails_safely_when_a_password_variable_is_missing(): void
    {
        config(['app.env' => 'local']);
        $this->setLocalDemoPasswords();
        $this->setEnvironment(AccessBootstrapService::BUYER_PASSWORD_ENV, null);

        $this->assertSame(Command::FAILURE, Artisan::call('app:bootstrap-local-demo'));
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Organization::query()->count());
        $this->assertSame(0, Plan::query()->count());
        $this->assertStringContainsString(AccessBootstrapService::BUYER_PASSWORD_ENV, Artisan::output());
    }

    public function test_local_demo_command_creates_reconciles_and_authorizes_only_the_expected_local_access(): void
    {
        config(['app.env' => 'local']);
        $passwords = $this->setLocalDemoPasswords();
        $unrelatedPassword = Str::password(32);
        $unrelatedUser = User::factory()->create(['password' => Hash::make($unrelatedPassword)]);
        $unrelatedOrganization = Organization::factory()->create(['name' => 'Unaffected Customer']);
        $this->membership($unrelatedUser, $unrelatedOrganization, Role::OrganizationOwner);

        $this->assertSame(Command::SUCCESS, Artisan::call('app:bootstrap-local-demo'));
        $output = Artisan::output();

        foreach ($passwords as $password) {
            $this->assertStringNotContainsString($password, $output);
        }

        $organization = Organization::query()
            ->where('slug', AccessBootstrapService::LOCAL_DEMO_ORGANIZATION_SLUG)
            ->sole();
        $location = $organization->restaurantLocations()->where('code', AccessBootstrapService::LOCAL_DEMO_LOCATION_CODE)->sole();
        $this->assertSame(AccessBootstrapService::LOCAL_DEMO_ORGANIZATION_NAME, $organization->name);
        $this->assertSame(AccessBootstrapService::LOCAL_DEMO_LOCATION_NAME, $location->name);
        $this->assertSame('active', $organization->status->value);
        $this->assertSame('active', $location->status->value);
        $this->assertSame('growth', $organization->latestSubscription()->sole()->plan->code);

        $this->assertSame(5, User::query()->whereIn('email', AccessBootstrapService::localDemoEmails())->count());
        $this->assertSame(6, User::query()->count());
        $this->assertTrue(Hash::check($unrelatedPassword, $unrelatedUser->refresh()->password));
        $this->assertSame(1, Organization::query()->whereKey($unrelatedOrganization->id)->count());

        $platformAdmin = User::query()->where('email', 'platform.admin@local.test')->sole();
        $ownerOne = User::query()->where('email', 'owner.one@local.test')->sole();
        $ownerTwo = User::query()->where('email', 'owner.two@local.test')->sole();
        $buyer = User::query()->where('email', 'buyer@local.test')->sole();
        $viewer = User::query()->where('email', 'viewer@local.test')->sole();

        $this->assertSame(Role::PlatformAdmin, $platformAdmin->platform_role);
        $this->assertSame(0, $platformAdmin->memberships()->where('organization_id', $organization->id)->count());
        $this->assertSame(Role::OrganizationOwner, $this->membershipFor($organization, $ownerOne)->role);
        $this->assertSame(Role::OrganizationOwner, $this->membershipFor($organization, $ownerTwo)->role);
        $this->assertSame(Role::Buyer, $this->membershipFor($organization, $buyer)->role);
        $this->assertSame(Role::Viewer, $this->membershipFor($organization, $viewer)->role);

        foreach (AccessBootstrapService::localDemoEmails() as $email) {
            $user = User::query()->where('email', $email)->sole();
            $this->assertStringNotContainsString($passwords[$email], $user->password);
            $this->assertTrue(Hash::check($passwords[$email], $user->password));
        }

        $this->asUser($platformAdmin)->getJson('/api/v1/platform/context')->assertOk()->assertJsonPath('scope', 'platform');
        $this->asUser($platformAdmin)->getJson("/api/v1/organizations/{$organization->id}/context")->assertForbidden();
        $this->asUser($ownerOne)->getJson("/api/v1/organizations/{$organization->id}/context")->assertOk();
        $this->asUser($buyer)->getJson('/api/v1/platform/context')->assertForbidden();
        $this->asUser($viewer)->getJson("/api/v1/organizations/{$organization->id}/context")->assertOk();
        $this->getJson("/api/v1/organizations/{$unrelatedOrganization->id}/context")->assertForbidden();

        $this->assertTrue(Gate::forUser($ownerOne)->allows('update', $organization));
        $this->assertTrue(Gate::forUser($buyer)->allows('create', [ImportRun::class, $organization]));
        $this->assertTrue(Gate::forUser($buyer)->allows('create', [SavedList::class, $organization]));
        $this->assertFalse(Gate::forUser($buyer)->allows('create', [RestaurantLocation::class, $organization]));
        $this->assertTrue(Gate::forUser($viewer)->allows('view', $location));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', [ImportRun::class, $organization]));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', [SavedList::class, $organization]));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', [RestaurantLocation::class, $organization]));
    }

    public function test_local_demo_command_is_idempotent_and_only_changes_a_demo_password_when_its_environment_value_changes(): void
    {
        config(['app.env' => 'development']);
        $passwords = $this->setLocalDemoPasswords();

        $this->assertSame(Command::SUCCESS, Artisan::call('app:bootstrap-local-demo'));
        $owner = User::query()->where('email', 'owner.one@local.test')->sole();
        $originalHash = $owner->password;
        $originalAuthVersion = $owner->auth_version;

        $this->assertSame(Command::SUCCESS, Artisan::call('app:bootstrap-local-demo'));
        $owner->refresh();
        $this->assertSame($originalHash, $owner->password);
        $this->assertSame($originalAuthVersion, $owner->auth_version);
        $this->assertSame(5, User::query()->whereIn('email', AccessBootstrapService::localDemoEmails())->count());
        $this->assertSame(4, OrganizationMembership::query()
            ->where('organization_id', Organization::query()->where('slug', AccessBootstrapService::LOCAL_DEMO_ORGANIZATION_SLUG)->sole()->id)
            ->where('status', MembershipStatus::Active)
            ->count());

        $replacementPassword = Str::password(32);
        $this->setEnvironment(AccessBootstrapService::OWNER_ONE_PASSWORD_ENV, $replacementPassword);
        $this->assertSame(Command::SUCCESS, Artisan::call('app:bootstrap-local-demo'));
        $owner->refresh();
        $this->assertNotSame($originalHash, $owner->password);
        $this->assertFalse(Hash::check($passwords['owner.one@local.test'], $owner->password));
        $this->assertTrue(Hash::check($replacementPassword, $owner->password));
        $this->assertSame($originalAuthVersion + 1, $owner->auth_version);
    }

    public function test_platform_admin_bootstrap_requires_confirmation_outside_local_and_never_creates_a_membership(): void
    {
        $password = Str::password(32);
        $this->setEnvironment(AccessBootstrapService::OPERATIONAL_PLATFORM_ADMIN_PASSWORD_ENV, $password);
        config(['app.env' => 'production']);

        $this->assertSame(Command::FAILURE, Artisan::call('app:bootstrap-platform-admin', ['email' => 'operator@example.test']));
        $this->assertDatabaseMissing('users', ['email' => 'operator@example.test']);
        $this->assertStringContainsString('--confirm', Artisan::output());

        $this->assertSame(Command::SUCCESS, Artisan::call('app:bootstrap-platform-admin', [
            'email' => 'operator@example.test', '--confirm' => true,
        ]));
        $admin = User::query()->where('email', 'operator@example.test')->sole();
        $this->assertSame(Role::PlatformAdmin, $admin->platform_role);
        $this->assertSame(0, $admin->memberships()->count());
        $this->assertTrue(Hash::check($password, $admin->password));
        $this->assertStringNotContainsString($password, Artisan::output());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'platform_admin.bootstrap_created', 'actor_id' => $admin->id, 'subject_id' => $admin->id,
        ]);

        $this->asUser($admin)->getJson('/api/v1/platform/context')->assertOk();
        $this->assertSame(Command::SUCCESS, Artisan::call('app:bootstrap-platform-admin', [
            'email' => 'operator@example.test', '--confirm' => true,
        ]));
        $this->assertSame(1, User::query()->where('platform_role', Role::PlatformAdmin)->count());
        $this->assertStringNotContainsString($password, Artisan::output());
    }

    public function test_platform_admin_bootstrap_refuses_to_create_a_second_platform_identity(): void
    {
        config(['app.env' => 'local']);
        $this->setEnvironment(AccessBootstrapService::OPERATIONAL_PLATFORM_ADMIN_PASSWORD_ENV, Str::password(32));

        $this->assertSame(Command::SUCCESS, Artisan::call('app:bootstrap-platform-admin', ['email' => 'first@example.test']));
        $this->assertSame(Command::FAILURE, Artisan::call('app:bootstrap-platform-admin', ['email' => 'second@example.test']));
        $this->assertDatabaseMissing('users', ['email' => 'second@example.test']);
    }

    /** @return array<string, string> */
    private function setLocalDemoPasswords(): array
    {
        $passwords = [];
        foreach (AccessBootstrapService::localDemoEmails() as $index => $email) {
            $name = AccessBootstrapService::localDemoPasswordEnvironmentNames()[$index];
            $passwords[$email] = Str::password(32);
            $this->setEnvironment($name, $passwords[$email]);
        }

        return $passwords;
    }

    private function setEnvironment(string $name, ?string $value): void
    {
        if (! array_key_exists($name, $this->originalEnvironment)) {
            $this->originalEnvironment[$name] = getenv($name);
        }

        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function membershipFor(Organization $organization, User $user): OrganizationMembership
    {
        return OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->sole();
    }
}
