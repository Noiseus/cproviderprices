<?php

use App\Enums\EntitlementKey;
use App\Enums\InvitationStatus;
use App\Enums\MappingOrigin;
use App\Enums\MatchClass;
use App\Enums\MatchConfidence;
use App\Enums\OfferAvailability;
use App\Enums\OrganizationStatus;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Enums\SupplierAccountStatus;
use App\Enums\SupplierRefreshCadence;
use App\Enums\SupplierSourceMethod;
use App\Jobs\RecalculatePriceFreshness;
use App\Models\CanonicalProduct;
use App\Models\ImportFile;
use App\Models\ImportRun;
use App\Models\Invitation;
use App\Models\NormalizedImportRecord;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\PriceObservation;
use App\Models\RawImportRecord;
use App\Models\RestaurantLocation;
use App\Models\Subscription;
use App\Models\SupplierAccount;
use App\Models\SupplierDefinition;
use App\Models\SupplierOffer;
use App\Models\User;
use App\Models\VendorItem;
use App\Models\VendorItemMapping;
use App\Services\CatalogMatcher;
use App\Support\CatalogText;
use Database\Seeders\CatalogTaxonomySeeder;
use Database\Seeders\SupplierDefinitionSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;

Artisan::command('app:prepare-auth-e2e {--import-fixtures-only : Regenerate synthetic import files without creating users}', function (): void {
    if (! app()->environment(['local', 'testing'])) {
        throw new RuntimeException('Authentication E2E fixtures are restricted to local and testing environments.');
    }
    if ($this->option('import-fixtures-only')) {
        require base_path('tests/Fixtures/generate-import-xlsx.php');
        $this->info('Synthetic import fixtures are available in tests/Fixtures.');

        return;
    }
    if (config('database.connections.pgsql.database') !== 'cproviderprices_e2e' || config('e2e.isolated') !== true) {
        throw new RuntimeException('Browser fixtures require the isolated cproviderprices_e2e database and E2E_ISOLATED=1.');
    }

    $loginPassword = Str::password(24);
    $loginUser = User::factory()->create(['password' => Hash::make($loginPassword)]);
    $loginOrganization = Organization::factory()->create();
    OrganizationMembership::factory()->for($loginUser)->for($loginOrganization)->create(['role' => Role::OrganizationOwner]);
    $teamMember = User::factory()->create();
    OrganizationMembership::factory()->for($teamMember)->for($loginOrganization)->create(['role' => Role::Viewer]);
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
    Artisan::call('db:seed', ['--class' => SupplierDefinitionSeeder::class, '--force' => true]);
    Artisan::call('db:seed', ['--class' => CatalogTaxonomySeeder::class, '--force' => true]);

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

    $workspacePassword = Str::password(24);
    $workspaceUser = User::factory()->create(['password' => Hash::make($workspacePassword)]);
    $workspaceOrganization = Organization::factory()->create();
    OrganizationMembership::factory()->for($workspaceUser)->for($workspaceOrganization)->create(['role' => Role::OrganizationOwner]);
    $workspaceMember = User::factory()->create();
    OrganizationMembership::factory()->for($workspaceMember)->for($workspaceOrganization)->create(['role' => Role::Viewer]);
    Subscription::factory()->for($workspaceOrganization)->for($plan)->create();

    // Prompt 14/15 acceptance data has its own customer workspace so it cannot
    // consume location or supplier-account allowances exercised by earlier
    // browser regression fixtures. These price facts are explicit source
    // observations, so the E2E journey never depends on a guessed timestamp.
    $searchPassword = Str::password(24);
    $searchUser = User::factory()->create(['password' => Hash::make($searchPassword)]);
    $searchOrganization = Organization::factory()->create(['name' => 'Search comparison E2E workspace']);
    OrganizationMembership::factory()->for($searchUser)->for($searchOrganization)->create(['role' => Role::OrganizationOwner]);
    Subscription::factory()->for($searchOrganization)->for($plan)->create();
    $beefsteak = CanonicalProduct::query()->where('normalized_key', 'beefsteak tomato')->sole();
    $searchLocation = RestaurantLocation::factory()->for($searchOrganization)->create(['name' => 'Search Kitchen']);
    $searchOffers = [];
    $makeSearchOffer = function (string $supplierName, array $input) use ($searchOrganization, $searchUser, $searchLocation, $beefsteak): SupplierOffer {
        $definition = SupplierDefinition::factory()->create([
            'name' => $supplierName,
            'code' => Str::slug($supplierName).'-'.Str::lower(Str::random(6)),
        ]);
        $account = SupplierAccount::query()->create([
            'organization_id' => $searchOrganization->id,
            'restaurant_location_id' => $searchLocation->id,
            'supplier_definition_id' => $definition->id,
            'display_name' => $supplierName,
            'masked_account_reference' => null,
            'source_method' => SupplierSourceMethod::ManualImport,
            'expected_refresh_cadence' => SupplierRefreshCadence::Weekly,
            'status' => SupplierAccountStatus::Active,
        ]);
        $run = ImportRun::query()->create([
            'organization_id' => $searchOrganization->id,
            'restaurant_location_id' => $searchLocation->id,
            'supplier_account_id' => $account->id,
            'created_by' => $searchUser->id,
            'fingerprint' => hash('sha256', 'search-comparison-'.$supplierName.'-'.Str::uuid7()),
            'request_id' => 'search-comparison-'.Str::uuid7(),
        ]);
        $file = ImportFile::query()->create([
            'organization_id' => $searchOrganization->id,
            'import_run_id' => $run->id,
            'original_filename' => 'beefsteak-search-comparison.csv',
            'storage_key' => 'e2e/search-comparison/'.Str::uuid7(),
            'size_bytes' => 1,
            'media_type' => 'text/csv',
            'format' => 'csv',
        ]);
        $price = (string) $input['price'];
        $observedAt = $input['observed_at'];
        $raw = RawImportRecord::query()->create([
            'organization_id' => $searchOrganization->id,
            'import_run_id' => $run->id,
            'import_file_id' => $file->id,
            'source_row' => 2,
            'raw_values' => ['SKU' => $input['sku'], 'Description' => $input['description']],
            'mapped_values' => [
                'supplier_sku' => $input['sku'],
                'description' => $input['description'],
                'pack_size_text' => $input['pack'],
                'price' => $price,
                'currency' => 'USD',
                'observed_at' => $observedAt->format(DateTimeInterface::ATOM),
                'availability' => $input['availability']->value,
            ],
            'status' => 'normalized',
        ]);
        $quantity = $input['quantity'] ?? null;
        $normalized = NormalizedImportRecord::query()->create([
            'organization_id' => $searchOrganization->id,
            'import_run_id' => $run->id,
            'raw_import_record_id' => $raw->id,
            'parser_version' => '1.0.0',
            'normalized_values' => [
                'packs' => $quantity === null ? [] : [['case_total' => $quantity, 'case_total_unit' => 'g', 'unit_size' => ['dimension' => 'weight']]],
                'measurements' => [],
                'case_quantity' => null,
                'size_code' => '5X6',
                'variable_weight' => $quantity === null,
            ],
            'warnings' => $quantity === null ? [['code' => 'variable_weight', 'field' => 'pack_size_text', 'message' => 'Variable weight requires review.']] : [],
            'confidence' => $quantity === null ? 'low' : 'high',
        ]);
        $vendor = VendorItem::query()->create([
            'organization_id' => $searchOrganization->id,
            'supplier_account_id' => $account->id,
            'first_normalized_import_record_id' => $normalized->id,
            'last_normalized_import_record_id' => $normalized->id,
            'supplier_item_identifier' => $input['sku'],
            'identity_key' => 'sku:'.$input['sku'],
            'raw_description' => $input['description'],
            'normalized_description' => CatalogText::normalize($input['description']),
            'brand' => $input['brand'] ?? null,
            'normalized_attributes' => ['size_code' => '5X6', 'dimensions' => ['weight']],
            'parser_version' => '1.0.0',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        VendorItemMapping::query()->create([
            'vendor_item_id' => $vendor->id,
            'canonical_product_id' => $beefsteak->id,
            'product_variant_id' => null,
            'match_candidate_id' => null,
            'reviewed_by' => null,
            'supersedes_id' => null,
            'match_class' => $input['match_class'],
            'confidence' => MatchConfidence::B,
            'origin' => MappingOrigin::Manual,
            'matcher_version' => 'catalog-matcher-1.0.0',
            'evidence' => ['stage' => 'search_comparison_e2e', 'size_code' => '5X6'],
            'is_current' => true,
            'verified_at' => now(),
        ]);
        $offer = SupplierOffer::query()->create([
            'organization_id' => $searchOrganization->id,
            'restaurant_location_id' => $searchLocation->id,
            'supplier_account_id' => $account->id,
            'vendor_item_id' => $vendor->id,
            'is_active' => true,
        ]);
        $observation = PriceObservation::query()->create([
            'organization_id' => $searchOrganization->id,
            'supplier_offer_id' => $offer->id,
            'import_run_id' => $run->id,
            'normalized_import_record_id' => $normalized->id,
            'case_price' => $price,
            'currency' => 'USD',
            'source_price_evidence' => ['source_price' => $price, 'source_currency' => 'USD', 'source_observed_at' => $observedAt->format(DateTimeInterface::ATOM)],
            'source_pack_evidence' => ['packs' => [['original_text' => $input['pack']]], 'warnings' => $normalized->warnings],
            'normalized_quantity' => $quantity,
            'normalized_unit' => $quantity === null ? null : 'g',
            'dimension' => $quantity === null ? null : 'weight',
            'availability' => $input['availability'],
            'source_updated_at' => null,
            'observed_at' => $observedAt,
            'imported_at' => now(),
            'formula_version' => 'price-comparison-1.0.0',
        ]);
        $offer->update(['current_price_observation_id' => $observation->id]);

        return $offer->refresh();
    };
    $searchOffers['lowest_case'] = $makeSearchOffer('Lowest case produce', ['sku' => 'BEEF-CASE', 'description' => 'TOM BEEF 5X6 1/10 LB CS', 'pack' => '1/10 LB CS', 'price' => '18.00', 'quantity' => '4535.9237', 'availability' => OfferAvailability::Unavailable, 'observed_at' => now()->subHours(2), 'match_class' => MatchClass::ExactMatch]);
    $searchOffers['best_normalized'] = $makeSearchOffer('Normalized value produce', ['sku' => 'BEEF-NORMAL', 'description' => 'TOM BEEF 5X6 1/50 LB CS', 'pack' => '1/50 LB CS', 'price' => '30.00', 'quantity' => '22679.6185', 'availability' => OfferAvailability::Unavailable, 'observed_at' => now()->subHours(3), 'match_class' => MatchClass::EquivalentProduct]);
    $searchOffers['best_available'] = $makeSearchOffer('Available produce', ['sku' => 'BEEF-AVAILABLE', 'description' => 'TOM BEEF 5X6 1/30 LB CS', 'pack' => '1/30 LB CS', 'price' => '21.00', 'quantity' => '13607.7711', 'availability' => OfferAvailability::Available, 'observed_at' => now()->subHour(), 'match_class' => MatchClass::EquivalentProduct]);
    $searchOffers['alternative'] = $makeSearchOffer('Alternative produce', ['sku' => 'BEEF-ALT', 'description' => 'TOM BEEF ALT 5X6 1/25 LB CS', 'pack' => '1/25 LB CS', 'price' => '10.00', 'quantity' => '11339.80925', 'availability' => OfferAvailability::Available, 'observed_at' => now()->subHour(), 'match_class' => MatchClass::ComparableAlternative]);
    $searchOffers['unsafe'] = $makeSearchOffer('Variable-weight produce', ['sku' => 'BEEF-VAR', 'description' => 'TOM BEEF 5X6 VARIABLE WEIGHT', 'pack' => 'VARIABLE WEIGHT', 'price' => '15.00', 'quantity' => null, 'availability' => OfferAvailability::Available, 'observed_at' => now()->subHour(), 'match_class' => MatchClass::EquivalentProduct]);
    $searchOffers['stale'] = $makeSearchOffer('Stale produce', ['sku' => 'BEEF-STALE', 'description' => 'TOM BEEF 5X6 1/25 LB CS', 'pack' => '1/25 LB CS', 'price' => '22.00', 'quantity' => '11339.80925', 'availability' => OfferAvailability::Available, 'observed_at' => now()->subDays(15), 'match_class' => MatchClass::EquivalentProduct]);

    $workspaceViewerPassword = Str::password(24);
    $workspaceViewer = User::factory()->create(['password' => Hash::make($workspaceViewerPassword)]);
    $workspaceViewerOrganization = Organization::factory()->create();
    OrganizationMembership::factory()->for($workspaceViewer)->for($workspaceViewerOrganization)->create(['role' => Role::Viewer]);
    Subscription::factory()->for($workspaceViewerOrganization)->for($plan)->create();

    $suspendedPassword = Str::password(24);
    $suspendedUser = User::factory()->create(['password' => Hash::make($suspendedPassword)]);
    $suspendedOrganization = Organization::factory()->create(['status' => OrganizationStatus::Suspended]);
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

    $platformPassword = Str::password(24);
    $platformUser = User::factory()->platformAdmin()->create(['password' => Hash::make($platformPassword)]);
    $platformOrganization = Organization::factory()->create(['name' => 'Platform E2E Customer']);
    Subscription::factory()->for($platformOrganization)->for($plan)->create([
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => now()->addWeek(),
    ]);
    Organization::factory()->count(5)->create();

    // Matching fixtures are platform-review-only. They contain no price or raw
    // file data and reuse the normal catalog matcher rather than a browser fake.
    $matcher = app(CatalogMatcher::class);
    $matching = [];
    foreach (['accept' => 'TOM BEEFSTEAK 25#', 'reject' => 'TOM BEEF 5X6 1/25 LB CS', 'choose' => 'TOM BEEFSTEAK FRESH 25#', 'create' => 'UNUSUAL VINE TOMATO'] as $key => $description) {
        $vendor = VendorItem::factory()->create([
            'raw_description' => $description,
            'normalized_description' => CatalogText::normalize($description),
            'normalized_attributes' => ['size_code' => str_contains($description, '5X6') ? '5X6' : null, 'variable_weight' => false, 'measurements' => [], 'packs' => [], 'case_quantity' => null, 'dimensions' => [], 'identifiers' => [], 'parser_confidence' => 'high', 'parser_warnings' => []],
        ]);
        $candidate = $matcher->generate($vendor)->firstWhere('canonical_product_id', $beefsteak->id) ?? $matcher->generate($vendor)->first();
        if ($candidate === null) {
            throw new RuntimeException('The matching E2E fixture did not generate a candidate.');
        }
        $matching[$key] = ['candidate_id' => $candidate->id, 'supplier_account_id' => $vendor->supplier_account_id, 'description' => $description, 'category_id' => $beefsteak->category_id];
    }

    // Dedicated import identities keep prior workspace tests independent.
    $importOrganization = Organization::factory()->create(['name' => 'Import E2E workspace']);
    Subscription::factory()->for($importOrganization)->for($plan)->create();
    $importIdentities = [];
    foreach (['owner' => Role::OrganizationOwner, 'buyer' => Role::Buyer, 'viewer' => Role::Viewer] as $label => $role) {
        $password = Str::password(24);
        $user = User::factory()->create(['password' => Hash::make($password)]);
        OrganizationMembership::factory()->for($user)->for($importOrganization)->create(['role' => $role]);
        $importIdentities[$label] = ['email' => $user->email, 'password' => $password, 'slug' => $importOrganization->slug, 'organization_id' => $importOrganization->id];
    }
    $importLocation = RestaurantLocation::factory()->for($importOrganization)->create(['name' => 'Import Kitchen']);
    $otherLocation = RestaurantLocation::factory()->for($importOrganization)->create(['name' => 'Other Kitchen']);
    RestaurantLocation::factory()->for($importOrganization)->create(['name' => 'Inactive Kitchen', 'status' => 'inactive']);
    $importAccounts = [];
    foreach (['CSV Supplier', 'XLSX Supplier', 'Cancel Supplier'] as $name) {
        $account = SupplierAccount::factory()->create(['organization_id' => $importOrganization->id, 'restaurant_location_id' => $importLocation->id, 'display_name' => $name]);
        $importAccounts[$name] = $account->id;
    }
    SupplierAccount::factory()->create(['organization_id' => $importOrganization->id, 'restaurant_location_id' => $otherLocation->id, 'display_name' => 'Other Location Supplier']);
    $disabledPassword = Str::password(24);
    $disabledUser = User::factory()->create(['password' => Hash::make($disabledPassword)]);
    $disabledOrganization = Organization::factory()->create();
    OrganizationMembership::factory()->for($disabledUser)->for($disabledOrganization)->create(['role' => Role::OrganizationOwner]);
    $disabledPlan = Plan::factory()->create();
    PlanEntitlement::factory()->for($disabledPlan)->create(['key' => EntitlementKey::ManualImportsEnabled, 'value' => ['enabled' => false]]);
    Subscription::factory()->for($disabledOrganization)->for($disabledPlan)->create();

    $path = storage_path('app/private/auth-e2e-isolated.json');
    file_put_contents($path, json_encode([
        'imports' => $importIdentities + ['accounts' => $importAccounts, 'location_id' => $importLocation->id, 'other_location_id' => $otherLocation->id,
            'disabled' => ['email' => $disabledUser->email, 'password' => $disabledPassword, 'slug' => $disabledOrganization->slug, 'organization_id' => $disabledOrganization->id]],
        'login' => ['email' => $loginUser->email, 'password' => $loginPassword, 'slug' => $loginOrganization->slug, 'switch_slug' => $switchOrganization->slug, 'switch_id' => $switchOrganization->id, 'name' => $loginUser->name, 'team_member_name' => $teamMember->name],
        'viewer' => ['email' => $viewerUser->email, 'password' => $viewerPassword, 'slug' => $viewerOrganization->slug],
        'workspace_login' => ['email' => $workspaceUser->email, 'password' => $workspacePassword, 'slug' => $workspaceOrganization->slug, 'name' => $workspaceUser->name, 'team_member_name' => $workspaceMember->name],
        'search_login' => ['email' => $searchUser->email, 'password' => $searchPassword, 'slug' => $searchOrganization->slug],
        'search_comparison' => [
            'location_id' => $searchLocation->id,
            'canonical_product_id' => $beefsteak->id,
            'offers' => array_map(static fn (SupplierOffer $offer): string => $offer->id, $searchOffers),
        ],
        'workspace_viewer' => ['email' => $workspaceViewer->email, 'password' => $workspaceViewerPassword, 'slug' => $workspaceViewerOrganization->slug],
        'suspended' => ['email' => $suspendedUser->email, 'password' => $suspendedPassword, 'slug' => $suspendedOrganization->slug, 'name' => $suspendedOrganization->name],
        'invitation' => ['token' => $invitationToken, 'password' => $invitationPassword, 'slug' => $invitationOrganization->slug],
        'expired_token' => $expiredToken,
        'platform' => [
            'email' => $platformUser->email,
            'password' => $platformPassword,
            'organization_id' => $platformOrganization->id,
            'organization_name' => $platformOrganization->name,
            'matching' => $matching,
        ],
    ], JSON_THROW_ON_ERROR));

    $this->info('Authentication E2E fixture created in ignored private storage.');
})->purpose('Create short-lived local authentication E2E data without logging credentials');

Artisan::command('app:verify-import-e2e', function (): void {
    if (config('database.connections.pgsql.database') !== 'cproviderprices_e2e' || config('e2e.isolated') !== true) {
        throw new RuntimeException('Verification requires the isolated browser database.');
    }
    $organization = Organization::query()->where('name', 'Import E2E workspace')->latest('id')->firstOrFail();
    foreach (['a' => 'partially_completed', 'b' => 'completed'] as $supplier => $status) {
        $file = ImportFile::query()->where('organization_id', $organization->id)->where('original_filename', 'import-supplier-'.$supplier.($supplier === 'a' ? '.csv' : '.xlsx'))->sole();
        $run = $file->importRun;
        $results = NormalizedImportRecord::query()->where('organization_id', $organization->id)->where('import_run_id', $run->id)->where('parser_version', '1.0.0');
        if ($run->status->value !== $status || $results->count() !== 3 || $run->processed_count !== 3) {
            throw new RuntimeException('Real UI import did not produce expected persisted outcomes.');
        }
        foreach ($results->get() as $result) {
            if ($result->normalized_values['correlation_id'] !== $run->request_id) {
                throw new RuntimeException('Import trace did not propagate.');
            }
        }
    }
    $this->info('Both real UI fixtures persisted three parser-1.0.0 results with matching correlation and expected terminal status.');
});

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

Schedule::job(new RecalculatePriceFreshness, 'freshness')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);
