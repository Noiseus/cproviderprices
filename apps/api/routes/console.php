<?php

use App\Enums\EntitlementKey;
use App\Enums\InvitationStatus;
use App\Enums\MappingOrigin;
use App\Enums\MatchClass;
use App\Enums\MatchConfidence;
use App\Enums\OfferAvailability;
use App\Enums\OrganizationStatus;
use App\Enums\PreferredLanguage;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Enums\SupplierAccountStatus;
use App\Enums\SupplierRefreshCadence;
use App\Enums\SupplierSourceMethod;
use App\Jobs\RecalculatePriceFreshness;
use App\Jobs\VerifyQueueProcessing;
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
use App\Models\SavedList;
use App\Models\SavedListItem;
use App\Models\Subscription;
use App\Models\SupplierAccount;
use App\Models\SupplierDefinition;
use App\Models\SupplierOffer;
use App\Models\User;
use App\Models\VendorItem;
use App\Models\VendorItemMapping;
use App\Services\CatalogMatcher;
use App\Services\OfferComparisonService;
use App\Support\CatalogText;
use Database\Seeders\CatalogTaxonomySeeder;
use Database\Seeders\SupplierDefinitionSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
    // The isolated browser database is disposable by contract. Recreate it
    // here so a retained diagnostic volume cannot make a later E2E run depend
    // on stale random identities or duplicate synthetic rows.
    Artisan::call('migrate:fresh', ['--force' => true]);

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
    $selectedThenUnavailable = $makeSearchOffer('Later unavailable produce', ['sku' => 'BEEF-LATER-UNAVAILABLE', 'description' => 'TOM BEEF 5X6 1/20 LB CS', 'pack' => '1/20 LB CS', 'price' => '16.00', 'quantity' => '9071.8474', 'availability' => OfferAvailability::Available, 'observed_at' => now()->subHour(), 'match_class' => MatchClass::EquivalentProduct]);
    $unavailableSelectionList = SavedList::query()->create([
        'organization_id' => $searchOrganization->id,
        'restaurant_location_id' => $searchLocation->id,
        'name' => 'Unavailable Beefsteak selection',
        'status' => 'active',
        'version' => 1,
        'created_by' => $searchUser->id,
        'updated_by' => $searchUser->id,
    ]);
    SavedListItem::query()->create([
        'saved_list_id' => $unavailableSelectionList->id,
        'organization_id' => $searchOrganization->id,
        'restaurant_location_id' => $searchLocation->id,
        'canonical_product_id' => $beefsteak->id,
        'product_variant_id' => null,
        'selected_offer_id' => $selectedThenUnavailable->id,
        'quantity_target' => '2.000000',
        'preferred_unit' => 'lb',
        'notes' => 'Keep this intent when the offer changes.',
        'sort_order' => 1,
        'version' => 1,
        'created_by' => $searchUser->id,
        'updated_by' => $searchUser->id,
    ]);
    // Emulates an offer becoming unavailable after a buyer chose it. Durable
    // intent must remain visible while current alternatives are derived.
    $selectedThenUnavailable->update(['is_active' => false]);

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

    // Prompt 22 keeps its commercial pilot separate from the narrow fixtures
    // above. Everything below is synthetic, scoped to the isolated browser
    // database, and uses deterministic names/codes so the fixture has a
    // stable, inspectable shape on every invocation.
    $pilotPlan = Plan::factory()->create(['name' => 'Pilot release E2E plan']);
    foreach ($entitlements as $key => $value) {
        PlanEntitlement::factory()->for($pilotPlan)->create([
            'key' => $key,
            'value' => in_array($key, [EntitlementKey::MaxLocations->value, EntitlementKey::MaxSupplierAccounts->value], true)
                ? ['limit' => 10]
                : $value,
        ]);
    }
    $pilotOrganization = Organization::query()->create([
        'name' => 'Pilot Bistro Group',
        'slug' => 'pilot-bistro-group',
        'status' => OrganizationStatus::Active,
    ]);
    Subscription::factory()->for($pilotOrganization)->for($pilotPlan)->create();
    $createPilotUser = static function (string $name, string $email, string $password, PreferredLanguage $language): User {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'preferred_language' => $language,
        ]);
    };
    $pilotOwnerPassword = Str::password(24);
    $pilotBuyerPassword = Str::password(24);
    $pilotViewerPassword = Str::password(24);
    $pilotOwner = $createPilotUser('Pilot Owner', 'pilot.owner@example.test', $pilotOwnerPassword, PreferredLanguage::English);
    $pilotBuyer = $createPilotUser('Pilot Buyer', 'pilot.buyer@example.test', $pilotBuyerPassword, PreferredLanguage::English);
    $pilotViewer = $createPilotUser('Pilot Viewer', 'pilot.viewer@example.test', $pilotViewerPassword, PreferredLanguage::Spanish);
    foreach ([[$pilotOwner, Role::OrganizationOwner], [$pilotBuyer, Role::Buyer], [$pilotViewer, Role::Viewer]] as [$user, $role]) {
        OrganizationMembership::factory()->for($user)->for($pilotOrganization)->create(['role' => $role]);
    }
    $pilotMainLocation = RestaurantLocation::factory()->for($pilotOrganization)->create([
        'name' => 'Pilot Downtown Kitchen',
        'code' => 'PILOT-DT',
        'city' => 'Exampleville',
        'region' => 'NC',
    ]);
    $pilotCateringLocation = RestaurantLocation::factory()->for($pilotOrganization)->create([
        'name' => 'Pilot Catering Kitchen',
        'code' => 'PILOT-CAT',
        'city' => 'Exampleville',
        'region' => 'NC',
    ]);
    $supplierDefinitions = SupplierDefinition::query()->whereIn('code', ['freshpoint', 'us-foods', 'sysco', 'performance-foodservice'])->get()->keyBy('code');
    $createPilotAccount = static function (Organization $organization, RestaurantLocation $location, SupplierDefinition $definition, string $displayName, SupplierSourceMethod $method, SupplierRefreshCadence $cadence, ?DateTimeInterface $lastSuccess = null, ?DateTimeInterface $lastFailure = null): SupplierAccount {
        return SupplierAccount::query()->create([
            'organization_id' => $organization->id,
            'restaurant_location_id' => $location->id,
            'supplier_definition_id' => $definition->id,
            'display_name' => $displayName,
            'masked_account_reference' => 'ending '.substr(hash('sha256', $displayName), 0, 4),
            'source_method' => $method,
            'expected_refresh_cadence' => $cadence,
            'status' => SupplierAccountStatus::Active,
            'last_successful_sync_at' => $lastSuccess,
            'next_expected_sync_at' => $lastSuccess === null ? null : now()->addDay(),
            'last_sync_failed_at' => $lastFailure,
            'last_sync_failure_code' => $lastFailure === null ? null : 'source_timeout',
        ]);
    };
    $pilotCsvAccount = $createPilotAccount($pilotOrganization, $pilotMainLocation, $supplierDefinitions['freshpoint'], 'Pilot Produce CSV', SupplierSourceMethod::ManualImport, SupplierRefreshCadence::Weekly);
    $pilotXlsxAccount = $createPilotAccount($pilotOrganization, $pilotMainLocation, $supplierDefinitions['us-foods'], 'Pilot Broadline XLSX', SupplierSourceMethod::FutureAutomaticSync, SupplierRefreshCadence::Daily, now()->subHour());
    $pilotStaleAccount = $createPilotAccount($pilotOrganization, $pilotMainLocation, $supplierDefinitions['sysco'], 'Pilot Stale Feed', SupplierSourceMethod::FutureAutomaticSync, SupplierRefreshCadence::Daily, now()->subDays(4));
    $pilotFailedAccount = $createPilotAccount($pilotOrganization, $pilotMainLocation, $supplierDefinitions['performance-foodservice'], 'Pilot Failed Connection', SupplierSourceMethod::FutureAutomaticSync, SupplierRefreshCadence::Daily, now()->subDays(2), now()->subMinutes(30));
    $createPilotSource = static function (SupplierAccount $account, User $actor, string $format, string $label): array {
        $run = ImportRun::query()->create([
            'organization_id' => $account->organization_id,
            'restaurant_location_id' => $account->restaurant_location_id,
            'supplier_account_id' => $account->id,
            'created_by' => $actor->id,
            'fingerprint' => hash('sha256', 'pilot-release-'.$label),
            'status' => 'completed',
            'request_id' => 'pilot-release-'.$label,
            'processed_count' => 0,
        ]);
        $file = ImportFile::query()->create([
            'organization_id' => $account->organization_id,
            'import_run_id' => $run->id,
            'original_filename' => 'pilot-'.$label.'.'.$format,
            'storage_key' => 'e2e/pilot-release/'.$label.'.'.$format,
            'size_bytes' => 1,
            'media_type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv',
            'format' => $format,
        ]);

        return ['run' => $run, 'file' => $file, 'next_row' => 2];
    };
    $pilotSources = [
        'csv' => $createPilotSource($pilotCsvAccount, $pilotBuyer, 'csv', 'produce-csv'),
        'xlsx' => $createPilotSource($pilotXlsxAccount, $pilotBuyer, 'xlsx', 'broadline-xlsx'),
        'stale' => $createPilotSource($pilotStaleAccount, $pilotBuyer, 'csv', 'stale-feed'),
        'failed' => $createPilotSource($pilotFailedAccount, $pilotBuyer, 'xlsx', 'failed-feed'),
    ];
    $createPilotEvidence = static function (array &$source, SupplierAccount $account, CanonicalProduct $product, string $sku, string $description, string $pack, ?string $quantity, ?string $unit, ?string $dimension, ?MatchClass $matchClass = MatchClass::EquivalentProduct): array {
        /** @var ImportRun $run */
        $run = $source['run'];
        /** @var ImportFile $file */
        $file = $source['file'];
        $row = $source['next_row']++;
        $raw = RawImportRecord::query()->create([
            'organization_id' => $account->organization_id,
            'import_run_id' => $run->id,
            'import_file_id' => $file->id,
            'source_row' => $row,
            'raw_values' => ['SKU' => $sku, 'Description' => $description, 'Pack' => $pack],
            'mapped_values' => ['supplier_sku' => $sku, 'description' => $description, 'pack_size_text' => $pack, 'observed_at' => now()->toIso8601String()],
            'status' => 'normalized',
        ]);
        $warnings = $quantity === null ? [['code' => 'variable_weight', 'field' => 'pack_size_text', 'message' => 'Variable or catch weight requires review.']] : [];
        $normalized = NormalizedImportRecord::query()->create([
            'organization_id' => $account->organization_id,
            'import_run_id' => $run->id,
            'raw_import_record_id' => $raw->id,
            'parser_version' => '1.0.0',
            'normalized_values' => [
                'packs' => $quantity === null ? [] : [['case_total' => $quantity, 'case_total_unit' => $unit, 'unit_size' => ['dimension' => $dimension]]],
                'measurements' => [],
                'case_quantity' => null,
                'size_code' => str_contains($description, '5X6') ? '5X6' : null,
                'variable_weight' => $quantity === null,
            ],
            'warnings' => $warnings,
            'confidence' => $quantity === null ? 'low' : 'high',
        ]);
        $vendor = VendorItem::query()->create([
            'organization_id' => $account->organization_id,
            'supplier_account_id' => $account->id,
            'first_normalized_import_record_id' => $normalized->id,
            'last_normalized_import_record_id' => $normalized->id,
            'supplier_item_identifier' => $sku,
            'identity_key' => 'sku:'.$sku,
            'raw_description' => $description,
            'normalized_description' => CatalogText::normalize($description),
            'brand' => 'Pilot Foods',
            'normalized_attributes' => [
                'size_code' => str_contains($description, '5X6') ? '5X6' : null,
                'dimensions' => $dimension === null ? [] : [$dimension],
                'parser_confidence' => $quantity === null ? 'low' : 'high',
                'parser_warnings' => $warnings,
                'variable_weight' => $quantity === null,
                'packs' => $quantity === null ? [] : [['case_total' => $quantity, 'case_total_unit' => $unit]],
                'measurements' => [],
                'case_quantity' => null,
                'identifiers' => [],
            ],
            'parser_version' => '1.0.0',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        if ($matchClass !== null) {
            VendorItemMapping::query()->create([
                'vendor_item_id' => $vendor->id,
                'canonical_product_id' => $product->id,
                'product_variant_id' => null,
                'match_candidate_id' => null,
                'reviewed_by' => null,
                'supersedes_id' => null,
                'match_class' => $matchClass,
                'confidence' => $matchClass === MatchClass::ExactMatch ? MatchConfidence::A : MatchConfidence::B,
                'origin' => MappingOrigin::Manual,
                'matcher_version' => CatalogMatcher::VERSION,
                'evidence' => ['stage' => 'pilot_release_fixture', 'source_row' => $row],
                'is_current' => true,
                'verified_at' => now(),
            ]);
        }

        return ['vendor' => $vendor, 'normalized' => $normalized, 'run' => $run, 'warnings' => $warnings];
    };
    $createPilotOffer = static function (array &$source, SupplierAccount $account, CanonicalProduct $product, array $input) use ($createPilotEvidence): array {
        $evidence = $createPilotEvidence(
            $source,
            $account,
            $product,
            $input['sku'],
            $input['description'],
            $input['pack'],
            $input['quantity'],
            $input['unit'],
            $input['dimension'],
            $input['match_class'],
        );
        /** @var VendorItem $vendor */
        $vendor = $evidence['vendor'];
        /** @var NormalizedImportRecord $normalized */
        $normalized = $evidence['normalized'];
        /** @var ImportRun $run */
        $run = $evidence['run'];
        $offer = SupplierOffer::query()->create([
            'organization_id' => $account->organization_id,
            'restaurant_location_id' => $account->restaurant_location_id,
            'supplier_account_id' => $account->id,
            'vendor_item_id' => $vendor->id,
            'is_active' => true,
        ]);
        $observation = PriceObservation::query()->create([
            'organization_id' => $account->organization_id,
            'supplier_offer_id' => $offer->id,
            'import_run_id' => $run->id,
            'normalized_import_record_id' => $normalized->id,
            'case_price' => $input['price'],
            'currency' => 'USD',
            'source_price_evidence' => ['source_price' => $input['price'], 'source_currency' => 'USD', 'source_observed_at' => $input['observed_at']->toIso8601String()],
            'source_pack_evidence' => ['packs' => [['original_text' => $input['pack']]], 'warnings' => $evidence['warnings']],
            'normalized_quantity' => $input['quantity'],
            'normalized_unit' => $input['unit'],
            'dimension' => $input['dimension'],
            'availability' => $input['availability'],
            'source_updated_at' => $input['source_updated_at'] ?? null,
            'observed_at' => $input['observed_at'],
            'imported_at' => now(),
            'formula_version' => 'price-comparison-1.0.0',
            'match_evidence' => ['stage' => 'pilot_release_fixture'],
        ]);
        $offer->update(['current_price_observation_id' => $observation->id]);

        return ['offer' => $offer->refresh(), 'observation' => $observation, 'source' => $source];
    };
    $pilotProducts = CanonicalProduct::query()->whereIn('normalized_key', [
        'beefsteak tomato', 'roma tomato', 'grape tomato', 'cherry tomato', 'vine ripe tomato',
        'chicken breast', 'canola oil', 'mozzarella cheese', 'all purpose flour', 'sparkling water',
    ])->get()->keyBy('normalized_key');
    $pilotCatalog = [
        ['count' => 24, 'product' => 'beefsteak tomato', 'prefix' => 'TOM BEEF 5X6 1/25 LB CS', 'pack' => '1/25 LB CS', 'quantity' => '11339.80925', 'unit' => 'g', 'dimension' => 'weight'],
        ['count' => 16, 'product' => 'roma tomato', 'prefix' => 'TOM ROMA 25 LB CS', 'pack' => '1/25 LB CS', 'quantity' => '11339.80925', 'unit' => 'g', 'dimension' => 'weight'],
        ['count' => 8, 'product' => 'grape tomato', 'prefix' => 'TOM GRAPE 10 LB CS', 'pack' => '1/10 LB CS', 'quantity' => '4535.9237', 'unit' => 'g', 'dimension' => 'weight'],
        ['count' => 8, 'product' => 'cherry tomato', 'prefix' => 'TOM CHERRY 12/1 PT CS', 'pack' => '12/1 PT CS', 'quantity' => '12', 'unit' => 'ea', 'dimension' => 'count'],
        ['count' => 8, 'product' => 'vine ripe tomato', 'prefix' => 'TOM VINE RIPE 15 LB CS', 'pack' => '1/15 LB CS', 'quantity' => '6803.88555', 'unit' => 'g', 'dimension' => 'weight'],
        ['count' => 20, 'product' => 'chicken breast', 'prefix' => 'CHIX BRST BNLS SKNLS 4/10 LB', 'pack' => '4/10 LB', 'quantity' => '18143.6948', 'unit' => 'g', 'dimension' => 'weight'],
        ['count' => 12, 'product' => 'canola oil', 'prefix' => 'OIL CANOLA 6/1 GAL', 'pack' => '6/1 GAL', 'quantity' => '22712.470704', 'unit' => 'mL', 'dimension' => 'volume'],
        ['count' => 12, 'product' => 'mozzarella cheese', 'prefix' => 'MOZZ WHOLE MILK 4/5 LB', 'pack' => '4/5 LB', 'quantity' => '9071.8474', 'unit' => 'g', 'dimension' => 'weight'],
        ['count' => 6, 'product' => 'all purpose flour', 'prefix' => 'FLOUR AP 1/25 LB', 'pack' => '1/25 LB', 'quantity' => '11339.80925', 'unit' => 'g', 'dimension' => 'weight'],
        ['count' => 6, 'product' => 'sparkling water', 'prefix' => 'WATER SPARKLING 24/12 OZ', 'pack' => '24 EA', 'quantity' => '24', 'unit' => 'ea', 'dimension' => 'count'],
    ];
    $pilotVendorCount = 0;
    foreach ($pilotCatalog as $template) {
        for ($number = 1; $number <= $template['count']; $number++) {
            $sourceKey = $pilotVendorCount % 2 === 0 ? 'csv' : 'xlsx';
            $createPilotEvidence(
                $pilotSources[$sourceKey],
                $sourceKey === 'csv' ? $pilotCsvAccount : $pilotXlsxAccount,
                $pilotProducts[$template['product']],
                sprintf('PILOT-%03d', $pilotVendorCount + 1),
                $template['prefix'].' PILOT '.sprintf('%03d', $number),
                $template['pack'],
                $template['quantity'],
                $template['unit'],
                $template['dimension'],
                MatchClass::EquivalentProduct,
            );
            $pilotVendorCount++;
        }
    }
    $pilotOffers = [];
    $pilotOffers['lowest_case'] = $createPilotOffer($pilotSources['csv'], $pilotCsvAccount, $beefsteak, ['sku' => 'PILOT-BEEF-CASE', 'description' => 'TOM BEEF 5X6 1/10 LB CS', 'pack' => '1/10 LB CS', 'price' => '12.00', 'quantity' => '4535.9237', 'unit' => 'g', 'dimension' => 'weight', 'availability' => OfferAvailability::Available, 'observed_at' => now()->subHours(3), 'match_class' => MatchClass::ExactMatch]);
    $pilotOffers['best_normalized'] = $createPilotOffer($pilotSources['csv'], $pilotCsvAccount, $beefsteak, ['sku' => 'PILOT-BEEF-NORMAL', 'description' => 'TOM BEEF 5X6 1/50 LB CS', 'pack' => '1/50 LB CS', 'price' => '25.00', 'quantity' => '22679.6185', 'unit' => 'g', 'dimension' => 'weight', 'availability' => OfferAvailability::Unavailable, 'observed_at' => now()->subHours(2), 'match_class' => MatchClass::EquivalentProduct]);
    $pilotOffers['best_available'] = $createPilotOffer($pilotSources['xlsx'], $pilotXlsxAccount, $beefsteak, ['sku' => 'PILOT-BEEF-AVAILABLE', 'description' => 'TOM BEEFSTEAK 25#', 'pack' => '1/30 LB CS', 'price' => '21.00', 'quantity' => '13607.7711', 'unit' => 'g', 'dimension' => 'weight', 'availability' => OfferAvailability::Available, 'observed_at' => now()->subHour(), 'source_updated_at' => now()->subHour(), 'match_class' => MatchClass::EquivalentProduct]);
    $pilotOffers['same_case_different_normalized'] = $createPilotOffer($pilotSources['xlsx'], $pilotXlsxAccount, $beefsteak, ['sku' => 'PILOT-BEEF-SAME-CASE', 'description' => 'TOM BEEF 5X6 1/25 LB CS', 'pack' => '1/25 LB CS', 'price' => '25.00', 'quantity' => '11339.80925', 'unit' => 'g', 'dimension' => 'weight', 'availability' => OfferAvailability::Available, 'observed_at' => now()->subHours(4), 'source_updated_at' => now()->subHours(4), 'match_class' => MatchClass::EquivalentProduct]);
    $pilotOffers['stale'] = $createPilotOffer($pilotSources['stale'], $pilotStaleAccount, $beefsteak, ['sku' => 'PILOT-BEEF-STALE', 'description' => 'TOM BEEF STALE 1/25 LB CS', 'pack' => '1/25 LB CS', 'price' => '16.00', 'quantity' => '11339.80925', 'unit' => 'g', 'dimension' => 'weight', 'availability' => OfferAvailability::Available, 'observed_at' => now()->subDays(4), 'source_updated_at' => now()->subDays(4), 'match_class' => MatchClass::EquivalentProduct]);
    $pilotOffers['connection_failed'] = $createPilotOffer($pilotSources['failed'], $pilotFailedAccount, $beefsteak, ['sku' => 'PILOT-BEEF-FAILED', 'description' => 'TOM BEEF CONNECTION FAILED 1/25 LB CS', 'pack' => '1/25 LB CS', 'price' => '15.00', 'quantity' => '11339.80925', 'unit' => 'g', 'dimension' => 'weight', 'availability' => OfferAvailability::Available, 'observed_at' => now()->subHours(2), 'source_updated_at' => now()->subHours(2), 'match_class' => MatchClass::EquivalentProduct]);
    $pilotOffers['alternative'] = $createPilotOffer($pilotSources['csv'], $pilotCsvAccount, $beefsteak, ['sku' => 'PILOT-BEEF-ALT', 'description' => 'TOM HEIRLOOM ALT 1/25 LB CS', 'pack' => '1/25 LB CS', 'price' => '8.00', 'quantity' => '11339.80925', 'unit' => 'g', 'dimension' => 'weight', 'availability' => OfferAvailability::Available, 'observed_at' => now()->subHour(), 'match_class' => MatchClass::ComparableAlternative]);
    $pilotOffers['variable_weight'] = $createPilotOffer($pilotSources['csv'], $pilotCsvAccount, $beefsteak, ['sku' => 'PILOT-BEEF-VAR', 'description' => 'TOM BEEF VARIABLE CATCH WEIGHT', 'pack' => 'VARIABLE WEIGHT', 'price' => '14.00', 'quantity' => null, 'unit' => null, 'dimension' => null, 'availability' => OfferAvailability::Available, 'observed_at' => now()->subHour(), 'match_class' => MatchClass::EquivalentProduct]);
    $pilotOffers['not_comparable'] = $createPilotOffer($pilotSources['xlsx'], $pilotXlsxAccount, $beefsteak, ['sku' => 'PILOT-BEEF-NOT-COMP', 'description' => 'TOM CHERRY 12/1 PT CS', 'pack' => '12/1 PT CS', 'price' => '11.00', 'quantity' => '12', 'unit' => 'ea', 'dimension' => 'count', 'availability' => OfferAvailability::Available, 'observed_at' => now()->subHour(), 'source_updated_at' => now()->subHour(), 'match_class' => MatchClass::NotComparable]);
    $historyOffer = $pilotOffers['best_available']['offer'];
    for ($day = 2; $day <= 13; $day++) {
        $historyEvidence = $createPilotEvidence($pilotSources['xlsx'], $pilotXlsxAccount, $beefsteak, 'PILOT-HISTORY-'.$day, 'TOM BEEF HISTORY 1/30 LB CS', '1/30 LB CS', '13607.7711', 'g', 'weight', MatchClass::EquivalentProduct);
        PriceObservation::query()->create([
            'organization_id' => $pilotOrganization->id,
            'supplier_offer_id' => $historyOffer->id,
            'import_run_id' => $historyEvidence['run']->id,
            'normalized_import_record_id' => $historyEvidence['normalized']->id,
            'case_price' => (string) (20 + $day),
            'currency' => 'USD',
            'source_price_evidence' => ['source_price' => (string) (20 + $day), 'source_currency' => 'USD', 'source_observed_at' => now()->subDays($day)->toIso8601String()],
            'source_pack_evidence' => ['packs' => [['original_text' => '1/30 LB CS']], 'warnings' => []],
            'normalized_quantity' => '13607.7711',
            'normalized_unit' => 'g',
            'dimension' => 'weight',
            'availability' => OfferAvailability::Available,
            'source_updated_at' => now()->subDays($day),
            'observed_at' => now()->subDays($day),
            'imported_at' => now(),
            'formula_version' => 'price-comparison-1.0.0',
            'match_evidence' => ['stage' => 'pilot_release_history'],
        ]);
    }
    $ambiguousEvidence = $createPilotEvidence($pilotSources['csv'], $pilotCsvAccount, $beefsteak, 'PILOT-BEEF-REVIEW', 'TOM BEEFSTEAK 25#', '1/25 LB CS', '11339.80925', 'g', 'weight', null);
    $ambiguousVendor = $ambiguousEvidence['vendor'];
    $ambiguousOffer = SupplierOffer::query()->create([
        'organization_id' => $pilotOrganization->id,
        'restaurant_location_id' => $pilotMainLocation->id,
        'supplier_account_id' => $pilotCsvAccount->id,
        'vendor_item_id' => $ambiguousVendor->id,
        'is_active' => true,
    ]);
    $ambiguousObservation = PriceObservation::query()->create([
        'organization_id' => $pilotOrganization->id,
        'supplier_offer_id' => $ambiguousOffer->id,
        'import_run_id' => $ambiguousEvidence['run']->id,
        'normalized_import_record_id' => $ambiguousEvidence['normalized']->id,
        'case_price' => '24.00',
        'currency' => 'USD',
        'source_price_evidence' => ['source_price' => '24.00', 'source_currency' => 'USD', 'source_observed_at' => now()->subHours(2)->toIso8601String()],
        'source_pack_evidence' => ['packs' => [['original_text' => '1/25 LB CS']], 'warnings' => []],
        'normalized_quantity' => '11339.80925',
        'normalized_unit' => 'g',
        'dimension' => 'weight',
        'availability' => OfferAvailability::Available,
        'observed_at' => now()->subHours(2),
        'imported_at' => now(),
        'formula_version' => 'price-comparison-1.0.0',
        'match_evidence' => null,
    ]);
    $ambiguousOffer->update(['current_price_observation_id' => $ambiguousObservation->id]);
    $ambiguousCandidate = $matcher->generate($ambiguousVendor)->firstWhere('canonical_product_id', $beefsteak->id);
    if ($ambiguousCandidate === null) {
        throw new RuntimeException('The pilot matching fixture did not create the Beefsteak review candidate.');
    }
    $pilotList = SavedList::query()->create([
        'organization_id' => $pilotOrganization->id,
        'restaurant_location_id' => $pilotMainLocation->id,
        'name' => 'Pilot weekly produce',
        'status' => 'active',
        'version' => 1,
        'created_by' => $pilotBuyer->id,
        'updated_by' => $pilotBuyer->id,
    ]);
    SavedListItem::query()->create([
        'saved_list_id' => $pilotList->id,
        'organization_id' => $pilotOrganization->id,
        'restaurant_location_id' => $pilotMainLocation->id,
        'canonical_product_id' => $beefsteak->id,
        'product_variant_id' => null,
        'selected_offer_id' => $pilotOffers['same_case_different_normalized']['offer']->id,
        'quantity_target' => '2.000000',
        'preferred_unit' => 'lb',
        'notes' => 'Synthetic pilot purchasing intent.',
        'sort_order' => 1,
        'version' => 1,
        'created_by' => $pilotBuyer->id,
        'updated_by' => $pilotBuyer->id,
    ]);
    $isolationPassword = Str::password(24);
    $isolationUser = $createPilotUser('Pilot Isolation Owner', 'pilot.isolation@example.test', $isolationPassword, PreferredLanguage::English);
    $isolationOrganization = Organization::query()->create(['name' => 'Pilot Isolation Tenant', 'slug' => 'pilot-isolation-tenant', 'status' => OrganizationStatus::Active]);
    OrganizationMembership::factory()->for($isolationUser)->for($isolationOrganization)->create(['role' => Role::OrganizationOwner]);
    Subscription::factory()->for($isolationOrganization)->for($pilotPlan)->create();
    $isolationLocation = RestaurantLocation::factory()->for($isolationOrganization)->create(['name' => 'Isolation Kitchen', 'code' => 'PILOT-ISO']);
    $isolationAccount = $createPilotAccount($isolationOrganization, $isolationLocation, $supplierDefinitions['freshpoint'], 'Isolation Produce CSV', SupplierSourceMethod::ManualImport, SupplierRefreshCadence::Weekly);
    $isolationSource = $createPilotSource($isolationAccount, $isolationUser, 'csv', 'isolation-csv');
    $isolationOffer = $createPilotOffer($isolationSource, $isolationAccount, $beefsteak, ['sku' => 'ISOLATION-BEEF-25', 'description' => 'ISOLATION TOM BEEF 1/25 LB CS', 'pack' => '1/25 LB CS', 'price' => '99.00', 'quantity' => '11339.80925', 'unit' => 'g', 'dimension' => 'weight', 'availability' => OfferAvailability::Available, 'observed_at' => now()->subHour(), 'match_class' => MatchClass::ExactMatch]);
    $isolationList = SavedList::query()->create([
        'organization_id' => $isolationOrganization->id,
        'restaurant_location_id' => $isolationLocation->id,
        'name' => 'Isolation private list',
        'status' => 'active',
        'version' => 1,
        'created_by' => $isolationUser->id,
        'updated_by' => $isolationUser->id,
    ]);
    $isolationItem = SavedListItem::query()->create([
        'saved_list_id' => $isolationList->id,
        'organization_id' => $isolationOrganization->id,
        'restaurant_location_id' => $isolationLocation->id,
        'canonical_product_id' => $beefsteak->id,
        'product_variant_id' => null,
        'selected_offer_id' => $isolationOffer['offer']->id,
        'quantity_target' => '1.000000',
        'preferred_unit' => 'lb',
        'sort_order' => 1,
        'version' => 1,
        'created_by' => $isolationUser->id,
        'updated_by' => $isolationUser->id,
    ]);

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
        'saved_lists' => [
            'unavailable_selection_id' => $unavailableSelectionList->id,
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
        'pilot' => [
            'owner' => ['email' => $pilotOwner->email, 'password' => $pilotOwnerPassword, 'slug' => $pilotOrganization->slug, 'organization_id' => $pilotOrganization->id],
            'buyer' => ['email' => $pilotBuyer->email, 'password' => $pilotBuyerPassword, 'slug' => $pilotOrganization->slug, 'organization_id' => $pilotOrganization->id],
            'viewer' => ['email' => $pilotViewer->email, 'password' => $pilotViewerPassword, 'slug' => $pilotOrganization->slug, 'organization_id' => $pilotOrganization->id],
            'locations' => ['main' => $pilotMainLocation->id, 'catering' => $pilotCateringLocation->id],
            'supplier_accounts' => ['csv' => $pilotCsvAccount->id, 'xlsx' => $pilotXlsxAccount->id, 'stale' => $pilotStaleAccount->id, 'failed' => $pilotFailedAccount->id],
            'catalog_vendor_item_count' => $pilotVendorCount,
            'comparison' => ['canonical_product_id' => $beefsteak->id, 'offers' => array_map(static fn (array $entry): string => $entry['offer']->id, $pilotOffers)],
            'history_offer_id' => $historyOffer->id,
            'saved_list_id' => $pilotList->id,
            'matching_candidate_id' => $ambiguousCandidate->id,
            'matching_offer_id' => $ambiguousOffer->id,
            'isolation' => [
                'email' => $isolationUser->email,
                'password' => $isolationPassword,
                'slug' => $isolationOrganization->slug,
                'organization_id' => $isolationOrganization->id,
                'location_id' => $isolationLocation->id,
                'supplier_account_id' => $isolationAccount->id,
                'saved_list_id' => $isolationList->id,
                'saved_list_item_id' => $isolationItem->id,
                'offer_id' => $isolationOffer['offer']->id,
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->info('Authentication E2E fixture created in ignored private storage.');
})->purpose('Create short-lived local authentication E2E data without logging credentials');

Artisan::command('app:verify-pilot-e2e', function (): void {
    if (config('database.connections.pgsql.database') !== 'cproviderprices_e2e' || config('e2e.isolated') !== true) {
        throw new RuntimeException('Pilot verification requires the isolated browser database.');
    }
    $organization = Organization::query()->where('slug', 'pilot-bistro-group')->sole();
    $roles = OrganizationMembership::query()
        ->where('organization_id', $organization->id)
        ->pluck('role')
        ->map(static fn (mixed $role): string => $role instanceof Role ? $role->value : (string) $role)
        ->all();
    if (array_diff([Role::OrganizationOwner->value, Role::Buyer->value, Role::Viewer->value], $roles) !== []) {
        throw new RuntimeException('Pilot organization is missing a required membership role.');
    }
    $locations = RestaurantLocation::query()->where('organization_id', $organization->id)->count();
    $accounts = SupplierAccount::query()->where('organization_id', $organization->id)->count();
    $vendorItems = VendorItem::query()->where('organization_id', $organization->id)->count();
    if ($locations < 2 || $accounts < 2 || $vendorItems < 100 || $vendorItems > 200) {
        throw new RuntimeException('Pilot data is outside its required organization, supplier, or 100-200 vendor-item bounds.');
    }
    $tomatoNames = ['Beefsteak Tomato', 'Roma Tomato', 'Grape Tomato', 'Cherry Tomato', 'Vine-ripe Tomato'];
    if (CanonicalProduct::query()->whereIn('english_name', $tomatoNames)->count() !== count($tomatoNames)) {
        throw new RuntimeException('Pilot tomato taxonomy is incomplete.');
    }
    $mainLocation = RestaurantLocation::query()->where('organization_id', $organization->id)->where('code', 'PILOT-DT')->sole();
    $beefsteak = CanonicalProduct::query()->where('normalized_key', 'beefsteak tomato')->sole();
    $comparison = app(OfferComparisonService::class)->compare($organization->id, $mainLocation, $beefsteak, []);
    $byLabel = [];
    foreach ($comparison['offers'] as $offer) {
        foreach ($offer['winner_labels'] as $label) {
            $byLabel[$label] = $offer;
        }
    }
    if (($byLabel['best_normalized_price']['normalized_price'] ?? null) !== '0.5'
        || ($byLabel['best_normalized_price']['availability'] ?? null) !== OfferAvailability::Unavailable->value
        || ($byLabel['best_available_option']['freshness'] ?? null) !== 'fresh') {
        throw new RuntimeException('Pilot comparison winners do not preserve the required safe normalized and available semantics.');
    }
    $failed = null;
    foreach ($comparison['offers'] as $offer) {
        if ($offer['freshness'] === 'connection_failed') {
            $failed = $offer;
            break;
        }
    }
    if ($failed === null || in_array('best_available_option', $failed['winner_labels'], true)) {
        throw new RuntimeException('A connection-failed pilot offer is missing or incorrectly eligible as best available.');
    }
    $historyOffer = SupplierOffer::query()->where('organization_id', $organization->id)
        ->whereHas('vendorItem', static fn ($query) => $query->where('supplier_item_identifier', 'PILOT-BEEF-AVAILABLE'))
        ->sole();
    if (PriceObservation::query()->where('supplier_offer_id', $historyOffer->id)->count() < 13) {
        throw new RuntimeException('Pilot price history is too short for chart and compatible-unit coverage.');
    }
    $pilotList = SavedList::query()->where('organization_id', $organization->id)->where('name', 'Pilot weekly produce')->sole();
    $pilotListItem = SavedListItem::query()->where('saved_list_id', $pilotList->id)->sole();
    $expectedSelectedOffer = SupplierOffer::query()->where('organization_id', $organization->id)
        ->whereHas('vendorItem', static fn ($query) => $query->where('supplier_item_identifier', 'PILOT-BEEF-SAME-CASE'))
        ->sole();
    if ($pilotListItem->selected_offer_id !== $expectedSelectedOffer->id) {
        throw new RuntimeException('Pilot saved-list selection does not preserve its required distinct offer.');
    }
    $ambiguous = VendorItem::query()->where('organization_id', $organization->id)->where('supplier_item_identifier', 'PILOT-BEEF-REVIEW')->sole();
    if ($ambiguous->currentMapping()->exists() || ! $ambiguous->matchCandidates()->where('status', 'pending')->exists()) {
        throw new RuntimeException('Pilot review item is not safely pending human review.');
    }
    $isolation = Organization::query()->where('slug', 'pilot-isolation-tenant')->sole();
    if (SavedList::query()->where('organization_id', $isolation->id)->count() !== 1
        || SupplierOffer::query()->where('organization_id', $isolation->id)->count() !== 1) {
        throw new RuntimeException('Pilot isolation tenant fixture is incomplete.');
    }
    $this->info(sprintf('Pilot E2E data verified: %d locations, %d supplier accounts, %d tenant-private vendor items, safe Beefsteak winners, pending review, and isolated tenant.', $locations, $accounts, $vendorItems));
})->purpose('Verify deterministic synthetic Prompt 22 pilot data in the isolated browser database');

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
        'APP_ENV' => config('app.env'),
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
        'MAIL_FROM_ADDRESS' => config('mail.from.address'),
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

    $environment = (string) config('app.env');
    if (in_array($environment, ['staging', 'production'], true)) {
        $productionRequired = [
            'APP_URL' => config('app.url'),
            'FRONTEND_URL' => config('app.frontend_url'),
            'SESSION_DOMAIN' => config('session.domain'),
            'TRUSTED_PROXIES' => config('security.trusted_proxies'),
            'REDIS_PASSWORD' => config('database.redis.default.password'),
            'MAIL_USERNAME' => config('mail.mailers.smtp.username'),
            'MAIL_PASSWORD' => config('mail.mailers.smtp.password'),
            'INGESTION_SERVICE_URL' => config('services.ingestion.url'),
            'INGESTION_SERVICE_TOKEN' => config('services.ingestion.token'),
        ];
        $productionMissing = array_keys(array_filter(
            $productionRequired,
            static fn (mixed $value): bool => (is_array($value) && $value === [])
                || ! is_array($value) && (! is_string($value) || trim($value) === ''),
        ));
        if ($productionMissing !== []) {
            $this->error('Missing required production environment values: '.implode(', ', $productionMissing));

            return Command::FAILURE;
        }

        $origins = config('cors.allowed_origins');
        $productionInvalid = [];
        if (config('app.debug') !== false) {
            $productionInvalid[] = 'APP_DEBUG';
        }
        if (config('session.secure') !== true) {
            $productionInvalid[] = 'SESSION_SECURE_COOKIE';
        }
        if (config('security.hsts_enabled') !== true) {
            $productionInvalid[] = 'SECURITY_HSTS_ENABLED';
        }
        if (config('logging.default') !== 'stderr') {
            $productionInvalid[] = 'LOG_CHANNEL';
        }
        if (config('app.maintenance.driver') !== 'cache') {
            $productionInvalid[] = 'APP_MAINTENANCE_DRIVER';
        }
        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $productionInvalid[] = 'APP_URL';
        }
        if (! str_starts_with((string) config('app.frontend_url'), 'https://')) {
            $productionInvalid[] = 'FRONTEND_URL';
        }
        if (! is_array($origins) || $origins === [] || array_filter(
            $origins,
            static fn (mixed $origin): bool => ! is_string($origin)
                || ! str_starts_with($origin, 'https://')
                || $origin === '*',
        ) !== []) {
            $productionInvalid[] = 'CORS_ALLOWED_ORIGINS';
        }
        if ($productionInvalid !== []) {
            $this->error('Invalid production environment values: '.implode(', ', $productionInvalid));

            return Command::FAILURE;
        }
    }

    $this->info('Environment configuration is valid.');

    return Command::SUCCESS;
})->purpose('Validate required API runtime configuration');

Artisan::command('app:verify-queue {--timeout=15 : Maximum seconds to await the worker acknowledgement}', function (): int {
    if (config('queue.default') !== 'redis') {
        $this->error('Queue smoke verification requires QUEUE_CONNECTION=redis.');

        return Command::FAILURE;
    }

    $timeout = min(30, max(1, (int) $this->option('timeout')));
    $acknowledgementKey = 'deployment-smoke:queue:'.Str::uuid()->toString();
    $cache = Cache::store('redis');
    $cache->forget($acknowledgementKey);
    VerifyQueueProcessing::dispatch($acknowledgementKey)->onQueue('default');

    $deadline = microtime(true) + $timeout;
    try {
        do {
            if ($cache->pull($acknowledgementKey) === true) {
                $this->info('Queue worker processed the non-destructive smoke job.');

                return Command::SUCCESS;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);
    } finally {
        $cache->forget($acknowledgementKey);
    }

    $this->error('Queue worker did not acknowledge the smoke job before the deadline.');

    return Command::FAILURE;
})->purpose('Verify that a Redis queue worker processes a non-destructive job');

Schedule::job(new RecalculatePriceFreshness, 'freshness')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);
