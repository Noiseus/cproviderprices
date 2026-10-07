<?php

namespace App\Services;

use App\Enums\LocationStatus;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\RestaurantLocation;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class AccessBootstrapService
{
    public const LOCAL_DEMO_ORGANIZATION_NAME = 'CProviderPrices Local Demo';

    public const LOCAL_DEMO_ORGANIZATION_SLUG = 'cproviderprices-local-demo';

    public const LOCAL_DEMO_LOCATION_NAME = 'Charlotte Test Location';

    public const LOCAL_DEMO_LOCATION_CODE = 'CHARLOTTE-TEST';

    public const PLATFORM_ADMIN_PASSWORD_ENV = 'LOCAL_PLATFORM_ADMIN_PASSWORD';

    public const OWNER_ONE_PASSWORD_ENV = 'LOCAL_OWNER_ONE_PASSWORD';

    public const OWNER_TWO_PASSWORD_ENV = 'LOCAL_OWNER_TWO_PASSWORD';

    public const BUYER_PASSWORD_ENV = 'LOCAL_BUYER_PASSWORD';

    public const VIEWER_PASSWORD_ENV = 'LOCAL_VIEWER_PASSWORD';

    public const OPERATIONAL_PLATFORM_ADMIN_PASSWORD_ENV = 'PLATFORM_ADMIN_BOOTSTRAP_PASSWORD';

    /** @var array<string, array{name: string, password_env: string, platform_role: Role|null, membership_role: Role|null}> */
    private const LOCAL_DEMO_ACCOUNTS = [
        'platform.admin@local.test' => [
            'name' => 'Platform Administrator',
            'password_env' => self::PLATFORM_ADMIN_PASSWORD_ENV,
            'platform_role' => Role::PlatformAdmin,
            'membership_role' => null,
        ],
        'owner.one@local.test' => [
            'name' => 'Organization Owner One',
            'password_env' => self::OWNER_ONE_PASSWORD_ENV,
            'platform_role' => null,
            'membership_role' => Role::OrganizationOwner,
        ],
        'owner.two@local.test' => [
            'name' => 'Organization Owner Two',
            'password_env' => self::OWNER_TWO_PASSWORD_ENV,
            'platform_role' => null,
            'membership_role' => Role::OrganizationOwner,
        ],
        'buyer@local.test' => [
            'name' => 'Buyer',
            'password_env' => self::BUYER_PASSWORD_ENV,
            'platform_role' => null,
            'membership_role' => Role::Buyer,
        ],
        'viewer@local.test' => [
            'name' => 'Viewer',
            'password_env' => self::VIEWER_PASSWORD_ENV,
            'platform_role' => null,
            'membership_role' => Role::Viewer,
        ],
    ];

    /** @return list<string> */
    public static function localDemoPasswordEnvironmentNames(): array
    {
        return array_values(array_map(
            static fn (array $account): string => $account['password_env'],
            self::LOCAL_DEMO_ACCOUNTS,
        ));
    }

    /** @return list<string> */
    public static function localDemoEmails(): array
    {
        return array_keys(self::LOCAL_DEMO_ACCOUNTS);
    }

    /** @return array<string, string> */
    public function localDemoPasswordsFromEnvironment(): array
    {
        $passwords = [];
        $missing = [];

        foreach (self::LOCAL_DEMO_ACCOUNTS as $email => $account) {
            $password = $this->passwordFromEnvironment($account['password_env']);
            if ($password === null) {
                $missing[] = $account['password_env'];

                continue;
            }

            $this->assertSafePassword($password, $account['password_env']);
            $passwords[$email] = $password;
        }

        if ($missing !== []) {
            throw new RuntimeException('Missing required local demo password environment variables: '.implode(', ', $missing).'.');
        }

        return $passwords;
    }

    /**
     * @param  array<string, string>  $passwords
     * @return array{organization: Organization, location: RestaurantLocation, accounts: array<string, User>}
     */
    public function bootstrapLocalDemo(array $passwords): array
    {
        $this->assertCompleteLocalDemoPasswords($passwords);

        // This is the established, idempotent source for the provider-neutral
        // plan and entitlements. It adds no customer or catalog fixture data.
        app(PlanSeeder::class)->run();

        return DB::transaction(function () use ($passwords): array {
            $organization = $this->resolveLocalDemoOrganization();
            $platformAdmin = null;
            $accounts = [];

            foreach (self::LOCAL_DEMO_ACCOUNTS as $email => $account) {
                $user = $this->reconcileUser(
                    $email,
                    $account['name'],
                    $account['platform_role'],
                    $passwords[$email],
                );
                $accounts[$email] = $user;

                if ($account['platform_role'] === Role::PlatformAdmin) {
                    $platformAdmin = $user;
                    $this->removeDemoOrganizationMembership($organization, $user);

                    continue;
                }

                $this->reconcileMembership($organization, $user, $account['membership_role']);
            }

            if (! $platformAdmin instanceof User) {
                throw new RuntimeException('The local platform administrator could not be reconciled.');
            }

            $this->reconcileGrowthSubscription($organization);
            $location = $this->resolveLocalDemoLocation($organization);

            Audit::record(
                'local_demo.bootstrap_reconciled',
                $platformAdmin,
                $organization->id,
                'organization',
                $organization->id,
                ['account_count' => count(self::LOCAL_DEMO_ACCOUNTS), 'location_code' => self::LOCAL_DEMO_LOCATION_CODE],
            );

            return ['organization' => $organization, 'location' => $location, 'accounts' => $accounts];
        });
    }

    public function bootstrapPlatformAdmin(string $email, string $password): User
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255) {
            throw new RuntimeException('A valid platform administrator email address is required.');
        }
        $this->assertSafePassword($password, self::OPERATIONAL_PLATFORM_ADMIN_PASSWORD_ENV);

        return DB::transaction(function () use ($email, $password): User {
            $currentAdmin = User::query()
                ->where('platform_role', Role::PlatformAdmin)
                ->lockForUpdate()
                ->first();

            if ($currentAdmin instanceof User && $currentAdmin->email !== $email) {
                throw new RuntimeException('A platform administrator already exists. Use the platform workspace for subsequent access changes.');
            }

            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            $created = ! $user instanceof User;
            $user ??= new User(['email' => $email]);

            $attributes = [
                'name' => 'Platform Administrator',
                'status' => UserStatus::Active,
                'platform_role' => Role::PlatformAdmin,
            ];
            $passwordChanged = $created || ! Hash::check($password, $user->password);
            if ($passwordChanged) {
                $attributes['password'] = Hash::make($password);
            }

            $user->fill($attributes);
            if (! $created && $passwordChanged) {
                $user->auth_version++;
            }
            $user->save();

            Audit::record(
                $created ? 'platform_admin.bootstrap_created' : 'platform_admin.bootstrap_reconciled',
                $user,
                subjectType: 'user',
                subjectId: $user->id,
                metadata: ['environment' => (string) config('app.env')],
            );

            return $user->refresh();
        });
    }

    public function passwordFromEnvironment(string $name): ?string
    {
        $password = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($password) && trim($password) !== '' ? $password : null;
    }

    public function assertSafePassword(string $password, string $environmentName): void
    {
        $normalized = mb_strtolower(trim($password));
        $compact = preg_replace('/[^a-z]/', '', $normalized) ?? '';

        if (mb_strlen($password) < 16
            || trim($password) !== $password
            || in_array($compact, ['password', 'changeme', 'changeit', 'secret', 'default', 'admin', 'demo', 'test', 'qwerty', 'letmein', 'welcome'], true)
            || preg_match('/^(.)\1+$/u', $password) === 1) {
            throw new RuntimeException('The password supplied through '.$environmentName.' is not safe for bootstrap use.');
        }
    }

    /** @param array<string, string> $passwords */
    private function assertCompleteLocalDemoPasswords(array $passwords): void
    {
        $missing = array_diff(self::localDemoEmails(), array_keys($passwords));
        if ($missing !== []) {
            throw new RuntimeException('All local demo passwords must be resolved before changing local data.');
        }

        foreach (self::LOCAL_DEMO_ACCOUNTS as $email => $account) {
            $this->assertSafePassword($passwords[$email], $account['password_env']);
        }
    }

    private function resolveLocalDemoOrganization(): Organization
    {
        $organization = Organization::query()
            ->where('slug', self::LOCAL_DEMO_ORGANIZATION_SLUG)
            ->lockForUpdate()
            ->first();
        $sameName = Organization::query()
            ->where('name', self::LOCAL_DEMO_ORGANIZATION_NAME)
            ->lockForUpdate()
            ->first();

        if ($organization instanceof Organization) {
            if ($organization->name !== self::LOCAL_DEMO_ORGANIZATION_NAME
                || ($sameName instanceof Organization && ! $sameName->is($organization))) {
                throw new RuntimeException('The local demo organization identifiers are already used by different data.');
            }
            if ($organization->status !== OrganizationStatus::Active) {
                $organization->update(['status' => OrganizationStatus::Active]);
            }

            return $organization->refresh();
        }

        if ($sameName instanceof Organization) {
            throw new RuntimeException('The local demo organization name is already used by different data.');
        }

        return Organization::query()->create([
            'name' => self::LOCAL_DEMO_ORGANIZATION_NAME,
            'slug' => self::LOCAL_DEMO_ORGANIZATION_SLUG,
            'status' => OrganizationStatus::Active,
        ]);
    }

    private function reconcileUser(string $email, string $name, ?Role $platformRole, string $password): User
    {
        $user = User::query()->where('email', $email)->lockForUpdate()->first();
        $created = ! $user instanceof User;
        $user ??= new User(['email' => $email]);

        $attributes = [
            'name' => $name,
            'status' => UserStatus::Active,
            'platform_role' => $platformRole,
        ];
        $passwordChanged = $created || ! Hash::check($password, $user->password);
        if ($passwordChanged) {
            $attributes['password'] = Hash::make($password);
        }

        $user->fill($attributes);
        if (! $created && $passwordChanged) {
            $user->auth_version++;
        }
        $user->save();

        return $user->refresh();
    }

    private function removeDemoOrganizationMembership(Organization $organization, User $user): void
    {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if ($membership instanceof OrganizationMembership && $membership->status !== MembershipStatus::Left) {
            $membership->update(['status' => MembershipStatus::Left, 'joined_at' => now()]);
        }
    }

    private function reconcileMembership(Organization $organization, User $user, ?Role $role): void
    {
        if (! $role instanceof Role || ! in_array($role, [Role::OrganizationOwner, Role::Buyer, Role::Viewer], true)) {
            throw new RuntimeException('A local demo membership role is invalid.');
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if (! $membership instanceof OrganizationMembership) {
            OrganizationMembership::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => $role,
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);

            return;
        }

        $membership->update([
            'role' => $role,
            'status' => MembershipStatus::Active,
            'joined_at' => $membership->joined_at ?? now(),
        ]);
    }

    private function reconcileGrowthSubscription(Organization $organization): void
    {
        $plan = Plan::query()->where('code', 'growth')->where('is_active', true)->lockForUpdate()->first();
        if (! $plan instanceof Plan) {
            throw new RuntimeException('The required active growth plan is unavailable.');
        }

        $subscription = $organization->subscriptions()
            ->orderByDesc('starts_at')
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->first();

        if (! $subscription instanceof Subscription) {
            $organization->subscriptions()->create([
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now(),
            ]);

            return;
        }

        if ($subscription->plan_id !== $plan->id
            || $subscription->status !== SubscriptionStatus::Active
            || $subscription->trial_ends_at !== null
            || $subscription->canceled_at !== null
            || $subscription->ends_at !== null) {
            $subscription->update([
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'trial_ends_at' => null,
                'canceled_at' => null,
                'ends_at' => null,
            ]);
        }
    }

    private function resolveLocalDemoLocation(Organization $organization): RestaurantLocation
    {
        $location = $organization->restaurantLocations()
            ->where('code', self::LOCAL_DEMO_LOCATION_CODE)
            ->lockForUpdate()
            ->first();
        $sameName = $organization->restaurantLocations()
            ->where('name', self::LOCAL_DEMO_LOCATION_NAME)
            ->lockForUpdate()
            ->first();

        if ($location instanceof RestaurantLocation) {
            if ($location->name !== self::LOCAL_DEMO_LOCATION_NAME
                || ($sameName instanceof RestaurantLocation && ! $sameName->is($location))) {
                throw new RuntimeException('The local demo location identifiers are already used by different data.');
            }
            if ($location->status !== LocationStatus::Active || $location->timezone !== 'America/New_York') {
                $location->update(['status' => LocationStatus::Active, 'timezone' => 'America/New_York']);
            }

            return $location->refresh();
        }

        if ($sameName instanceof RestaurantLocation) {
            throw new RuntimeException('The local demo location name is already used by different data.');
        }

        return $organization->restaurantLocations()->create([
            'name' => self::LOCAL_DEMO_LOCATION_NAME,
            'code' => self::LOCAL_DEMO_LOCATION_CODE,
            'status' => LocationStatus::Active,
            'timezone' => 'America/New_York',
            'city' => 'Charlotte',
            'region' => 'NC',
            'country_code' => 'US',
        ]);
    }
}
