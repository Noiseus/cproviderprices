<?php

use App\Enums\EntitlementKey;
use App\Enums\InvitationStatus;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;

Artisan::command('app:prepare-auth-e2e', function (): void {
    if (! app()->environment(['local', 'testing'])) {
        throw new RuntimeException('Authentication E2E fixtures are restricted to local and testing environments.');
    }

    $loginPassword = Str::password(24);
    $loginUser = User::factory()->create(['password' => Hash::make($loginPassword)]);
    $loginOrganization = Organization::factory()->create();
    OrganizationMembership::factory()->for($loginUser)->for($loginOrganization)->create(['role' => Role::OrganizationOwner]);
    $plan = Plan::factory()->create(['name' => 'E2E Plan']);
    $entitlements = [
        EntitlementKey::MaxLocations->value => ['limit' => 2],
        EntitlementKey::MaxUsers->value => ['limit' => 5],
        EntitlementKey::MaxSupplierAccounts->value => ['limit' => 1],
        EntitlementKey::ManualImportsEnabled->value => ['enabled' => true],
        EntitlementKey::AutomaticSyncEnabled->value => ['enabled' => false],
        EntitlementKey::PriceHistoryEnabled->value => ['enabled' => true],
        EntitlementKey::BasketOptimizerEnabled->value => ['enabled' => false],
    ];
    foreach ($entitlements as $key => $value) {
        PlanEntitlement::factory()->for($plan)->create(['key' => $key, 'value' => $value]);
    }
    Subscription::factory()->for($loginOrganization)->for($plan)->create();

    $switchOrganization = Organization::factory()->create();
    OrganizationMembership::factory()->for($loginUser)->for($switchOrganization)->create(['role' => Role::OrganizationOwner]);
    Subscription::factory()->for($switchOrganization)->for($plan)->create();

    $viewerPassword = Str::password(24);
    $viewerUser = User::factory()->create(['password' => Hash::make($viewerPassword)]);
    $viewerOrganization = Organization::factory()->create();
    OrganizationMembership::factory()->for($viewerUser)->for($viewerOrganization)->create(['role' => Role::Viewer]);
    $viewerPlan = Plan::factory()->create(['name' => 'Viewer E2E Plan']);
    foreach ($entitlements as $key => $value) {
        PlanEntitlement::factory()->for($viewerPlan)->create([
            'key' => $key,
            'value' => $key === EntitlementKey::PriceHistoryEnabled->value ? ['enabled' => false] : $value,
        ]);
    }
    Subscription::factory()->for($viewerOrganization)->for($viewerPlan)->create();

    $suspendedPassword = Str::password(24);
    $suspendedUser = User::factory()->create(['password' => Hash::make($suspendedPassword)]);
    $suspendedOrganization = Organization::factory()->create(['status' => \App\Enums\OrganizationStatus::Suspended]);
    OrganizationMembership::factory()->for($suspendedUser)->for($suspendedOrganization)->create();
    Subscription::factory()->for($suspendedOrganization)->for($plan)->create();

    $invitationToken = Str::random(64);
    $invitationPassword = Str::password(24);
    $invitationOrganization = Organization::factory()->create();
    Subscription::factory()->for($invitationOrganization)->for($plan)->create();
    Invitation::factory()->for($invitationOrganization)->withPlaintextToken($invitationToken)->create([
        'intended_role' => Role::OrganizationOwner,
    ]);

    $expiredToken = Str::random(64);
    Invitation::factory()->withPlaintextToken($expiredToken)->create([
        'status' => InvitationStatus::Expired,
        'expires_at' => now()->subMinute(),
    ]);

    $path = storage_path('app/private/auth-e2e.json');
    file_put_contents($path, json_encode([
        'login' => ['email' => $loginUser->email, 'password' => $loginPassword, 'slug' => $loginOrganization->slug, 'switch_slug' => $switchOrganization->slug, 'switch_id' => $switchOrganization->id],
        'viewer' => ['email' => $viewerUser->email, 'password' => $viewerPassword, 'slug' => $viewerOrganization->slug],
        'suspended' => ['email' => $suspendedUser->email, 'password' => $suspendedPassword, 'slug' => $suspendedOrganization->slug, 'name' => $suspendedOrganization->name],
        'invitation' => ['token' => $invitationToken, 'password' => $invitationPassword, 'slug' => $invitationOrganization->slug],
        'expired_token' => $expiredToken,
    ], JSON_THROW_ON_ERROR));

    $this->info('Authentication E2E fixture created in ignored private storage.');
})->purpose('Create short-lived local authentication E2E data without logging credentials');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:validate-environment', function (): int {
    $required = [
        'APP_KEY' => config('app.key'),
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => config('database.connections.pgsql.host'),
        'DB_DATABASE' => config('database.connections.pgsql.database'),
        'DB_USERNAME' => config('database.connections.pgsql.username'),
        'REDIS_HOST' => config('database.redis.default.host'),
        'AWS_ACCESS_KEY_ID' => config('filesystems.disks.s3.key'),
        'AWS_SECRET_ACCESS_KEY' => config('filesystems.disks.s3.secret'),
        'AWS_BUCKET' => config('filesystems.disks.s3.bucket'),
        'AWS_ENDPOINT' => config('filesystems.disks.s3.endpoint'),
        'MAIL_HOST' => config('mail.mailers.smtp.host'),
    ];
    $expected = [
        'DB_CONNECTION' => [config('database.default'), 'pgsql'],
        'CACHE_STORE' => [config('cache.default'), 'redis'],
        'QUEUE_CONNECTION' => [config('queue.default'), 'redis'],
        'FILESYSTEM_DISK' => [config('filesystems.default'), 's3'],
        'MAIL_MAILER' => [config('mail.default'), 'smtp'],
    ];

    $missing = array_keys(array_filter(
        $required,
        static fn (mixed $value): bool => ! is_string($value) || trim($value) === '',
    ));

    if ($missing !== []) {
        $this->error('Missing required environment values: '.implode(', ', $missing));

        return Command::FAILURE;
    }

    $invalid = array_keys(array_filter(
        $expected,
        static fn (array $values): bool => $values[0] !== $values[1],
    ));

    if ($invalid !== []) {
        $this->error('Invalid local service drivers: '.implode(', ', $invalid));

        return Command::FAILURE;
    }

    $this->info('Environment configuration is valid.');

    return Command::SUCCESS;
})->purpose('Validate required API runtime configuration');
