<?php

namespace Tests\Feature;

use App\Enums\EntitlementKey;
use App\Enums\ImportStatus;
use App\Enums\Role;
use App\Jobs\AwaitImportNormalization;
use App\Jobs\ValidateImportBatch;
use App\Models\AuditLog;
use App\Models\ImportError;
use App\Models\ImportFile;
use App\Models\ImportMappingTemplate;
use App\Models\ImportRun;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\RawImportRecord;
use App\Models\Subscription;
use App\Models\SupplierAccount;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTenantContext;
use Tests\TestCase;
use ZipArchive;

final class ImportCenterTest extends TestCase
{
    use BuildsTenantContext;
    use RefreshDatabase;

    private SupplierAccount $account;
    private Organization $organization;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Queue::fake();
        $this->account = SupplierAccount::factory()->create();
        $this->organization = $this->account->organization;
        $this->user = User::factory()->create();
        $this->membership($this->user, $this->organization, Role::Buyer);
        $plan = Plan::factory()->create();
        PlanEntitlement::factory()->for($plan)->create(['key' => EntitlementKey::ManualImportsEnabled, 'value' => ['enabled' => true]]);
        Subscription::factory()->for($this->organization)->for($plan)->create();
        $this->asUser($this->user);
    }

    public function test_csv_upload_preserves_bounded_headers_and_private_metadata(): void
    {
        $response = $this->upload()->assertCreated()->assertJsonPath('import.status', 'needs_mapping')
            ->assertJsonPath('file.headers.0', 'Item')->assertJsonPath('file.sample_rows.0.source_row', 2);
        $file = ImportFile::query()->sole();
        Storage::disk('s3')->assertExists($file->storage_key);
        $this->assertSame('private', Storage::disk('s3')->getVisibility($file->storage_key));
        $this->assertStringStartsWith('imports/'.$this->organization->id.'/', $file->storage_key);
        $this->assertStringNotContainsString($file->storage_key, $response->getContent());
        $this->assertStringNotContainsString('http', $response->getContent());
        $this->assertNotSame($file->original_filename, basename($file->storage_key));
    }

    public function test_xlsx_different_layout_maps_without_normalizing(): void
    {
        $id = $this->upload($this->fixture('b'))->assertCreated()->assertJsonPath('file.headers.0', 'Product Text')->json('import.id');
        $this->putJson($this->url($id.'/mapping'), ['mapping' => ['supplier_sku' => 'Vendor Code', 'description' => 'Product Text', 'pack_size_text' => 'Size Label', 'price' => 'Case Cost', 'unit' => 'UOM']])->assertOk();
        $this->validateRun($id);
        $this->assertDatabaseCount('raw_import_records', 3);
        $raw = RawImportRecord::query()->where('source_row', 2)->sole();
        $this->assertSame('TOM BEEFSTEAK 25#', $raw->mapped_values['description']);
        $this->assertSame('25#', $raw->mapped_values['pack_size_text']);
        $this->postJson($this->url($id.'/start'))->assertAccepted()->assertJsonPath('import.status', 'queued');
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_files_are_rejected(string $name, string $content): void
    {
        $this->upload(UploadedFile::fake()->createWithContent($name, $content))->assertUnprocessable();
        $this->assertDatabaseCount('import_runs', 0);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFiles(): iterable
    {
        yield 'malformed CSV' => ['prices.csv', "SKU,Description\n1,\"unfinished\n"];
        yield 'malformed XLSX' => ['prices.xlsx', 'not a zip'];
        yield 'wrong extension' => ['prices.exe', "SKU,Description\n1,Tomato\n"];
        yield 'wrong content type' => ['prices.csv', "%PDF-1.7\0binary"];
        yield 'empty' => ['prices.csv', ''];
        yield 'header only' => ['prices.csv', "SKU,Description\n"];
        yield 'duplicate headers' => ['prices.csv', "SKU,SKU\n1,Tomato\n"];
        yield 'empty header' => ['prices.csv', "SKU,\n1,Tomato\n"];
        yield 'path traversal' => ['..\\prices.csv', "SKU,Description\n1,Tomato\n"];
    }

    public function test_oversized_file_is_rejected_at_configured_ceiling(): void
    {
        config(['imports.max_kib' => 1]);
        $this->upload(UploadedFile::fake()->create('prices.csv', 2, 'text/csv'))->assertUnprocessable();
        $this->assertDatabaseCount('import_files', 0);
    }

    public function test_sample_is_limited_to_twenty_rows(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('many.csv', "SKU,Description\n".str_repeat("1,Tomato\n", 40)))
            ->assertCreated()->assertJsonCount(20, 'file.sample_rows');
        $this->assertDatabaseCount('raw_import_records', 0);
    }

    public function test_duplicate_bytes_ignore_filename_but_are_account_scoped(): void
    {
        $id = $this->upload()->assertCreated()->json('import.id');
        $this->upload(UploadedFile::fake()->createWithContent('renamed.csv', file_get_contents(base_path('tests/Fixtures/import-supplier-a.csv'))))
            ->assertConflict()->assertJsonPath('error.code', 'duplicate_import')->assertJsonPath('error.details.import_id', $id);
        $this->assertDatabaseCount('import_runs', 1);
        $this->assertCount(1, Storage::disk('s3')->allFiles());
        $other = SupplierAccount::factory()->create(['organization_id' => $this->organization->id, 'restaurant_location_id' => $this->account->restaurant_location_id]);
        $this->upload(null, ['supplier_account_id' => $other->id])->assertCreated();
    }

    public function test_cross_tenant_account_and_location_are_hidden(): void
    {
        $other = SupplierAccount::factory()->create();
        $this->upload(null, ['supplier_account_id' => $other->id])->assertNotFound();
        $this->upload(null, ['restaurant_location_id' => $other->restaurant_location_id])->assertNotFound();
        $this->upload(null, ['supplier_account_id' => $other->id, 'restaurant_location_id' => $other->restaurant_location_id])->assertNotFound();
    }

    public function test_client_organization_cannot_override_route_context(): void
    {
        $this->upload(null, ['organization_id' => Organization::factory()->create()->id])->assertUnprocessable();
        $this->getJson($this->url().'?organization_id='.$this->organization->id)->assertUnprocessable();
        $this->assertDatabaseCount('import_runs', 0);
    }

    public function test_viewer_reads_but_cannot_mutate_imports(): void
    {
        $id = $this->upload()->assertCreated()->json('import.id');
        $viewer = User::factory()->create();
        $this->membership($viewer, $this->organization, Role::Viewer);
        $this->asUser($viewer)->getJson($this->url($id))->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('pagination.total', 1);
        $this->upload()->assertForbidden();
        $this->putJson($this->url($id.'/mapping'), $this->mapping())->assertForbidden();
        foreach (['validate', 'start', 'cancel'] as $action) { $this->postJson($this->url($id.'/'.$action))->assertForbidden(); }
        $this->postJson($this->url($id.'/downloads'))->assertOk();
    }

    public function test_owner_can_upload(): void
    {
        $owner = User::factory()->create();
        $this->membership($owner, $this->organization, Role::OrganizationOwner);
        $this->asUser($owner);
        $this->upload()->assertCreated();
    }

    public function test_guest_and_nonmember_cannot_read_or_upload(): void
    {
        $id = $this->upload()->assertCreated()->json('import.id');
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url($id))->assertUnauthorized();
        $this->asUser(User::factory()->create())->getJson($this->url($id))->assertForbidden();
        $this->upload()->assertForbidden();
    }

    public function test_direct_ids_never_bypass_tenant_scope(): void
    {
        $id = $this->upload()->assertCreated()->json('import.id');
        $other = Organization::factory()->create();
        $this->membership($this->user, $other, Role::Buyer);
        $original = $this->organization;
        $this->organization = $other;
        $this->getJson($this->url($id))->assertNotFound();
        $this->postJson($this->url($id.'/downloads'))->assertNotFound();
        $this->postJson($this->url($id.'/cancel'))->assertNotFound();
        $this->getJson($this->url())->assertOk()->assertJsonCount(0, 'imports');
        $this->organization = $original;
    }

    public function test_feature_disabled_blocks_creation_and_start_but_not_history_or_cancel(): void
    {
        $id = $this->mapped();
        $this->validateRun($id);
        PlanEntitlement::query()->update(['value' => json_encode(['enabled' => false])]);
        app(EntitlementService::class)->forget($this->organization);
        $this->upload()->assertForbidden()->assertJsonPath('error.code', 'feature_disabled');
        $this->postJson($this->url($id.'/start'))->assertForbidden()->assertJsonPath('error.details.entitlement_key', 'manual_imports_enabled');
        $this->getJson($this->url($id))->assertOk();
        $this->postJson($this->url($id.'/cancel'))->assertOk();
    }

    public function test_mapping_templates_are_structural_and_snapshots_do_not_change(): void
    {
        $id = $this->mapped(['template_name' => 'Supplier A']);
        $template = ImportMappingTemplate::query()->sole();
        $snapshot = ImportRun::query()->findOrFail($id)->mapping_snapshot;
        $id2 = $this->upload(UploadedFile::fake()->createWithContent('more.csv', file_get_contents(base_path('tests/Fixtures/import-supplier-a.csv'))."00016,Extra,Pilot,1 CS,9\n"))->assertCreated()->json('import.id');
        $this->putJson($this->url($id2.'/mapping'), ['template_id' => $template->id])->assertOk()->assertJsonPath('import.mapping_template_id', $template->id);
        $this->putJson($this->url($id2.'/mapping'), ['mapping' => ['supplier_sku' => 'Item', 'description' => 'Brand'], 'template_name' => 'Supplier A'])->assertOk();
        $this->assertSame($snapshot, ImportRun::query()->findOrFail($id)->mapping_snapshot);
        $this->getJson('/api/v1/organizations/'.$this->organization->id.'/import-mapping-templates?supplier_account_id='.$this->account->id)->assertOk()->assertJsonCount(1, 'templates');
        $xlsx = $this->upload($this->fixture('b'))->assertCreated()->json('import.id');
        $this->putJson($this->url($xlsx.'/mapping'), ['template_id' => $template->id])->assertUnprocessable()->assertJsonPath('error.code', 'mapping_headers_mismatch');
    }

    public function test_cross_tenant_and_cross_account_templates_are_hidden(): void
    {
        $id = $this->upload()->assertCreated()->json('import.id');
        foreach ([SupplierAccount::factory()->create(), SupplierAccount::factory()->create(['organization_id' => $this->organization->id, 'restaurant_location_id' => $this->account->restaurant_location_id])] as $account) {
            $template = ImportMappingTemplate::query()->create(['organization_id' => $account->organization_id, 'supplier_account_id' => $account->id,
                'name' => 'Private', 'headers' => ['Item'], 'mapping' => ['supplier_sku' => 'Item'], 'source_conventions' => []]);
            $this->putJson($this->url($id.'/mapping'), ['template_id' => $template->id])->assertNotFound();
        }
    }

    public function test_mapping_requires_known_distinct_columns_and_required_fields(): void
    {
        $id = $this->upload()->assertCreated()->json('import.id');
        foreach ([['description' => 'Description'], ['supplier_sku' => 'Missing', 'description' => 'Description'], ['supplier_sku' => 'Item', 'description' => 'Item'], ['supplier_sku' => 'Item', 'description' => 'Description', 'secret' => 'Brand']] as $mapping) {
            $this->putJson($this->url($id.'/mapping'), ['mapping' => $mapping])->assertUnprocessable();
        }
    }

    public function test_validation_is_async_preserves_raw_strings_rows_and_partial_errors(): void
    {
        $id = $this->mapped();
        $this->postJson($this->url($id.'/validate'))->assertAccepted();
        Queue::assertPushed(ValidateImportBatch::class, fn (ValidateImportBatch $job): bool => $job->organizationId === $this->organization->id && $job->importId === $id && $job->connection === 'redis');
        $this->assertDatabaseCount('raw_import_records', 0);
        $this->runJob($id);
        $raw = RawImportRecord::query()->where('source_row', 2)->sole();
        $this->assertSame('00012', $raw->raw_values['Item']);
        $this->assertSame('1/25 LB CS', $raw->mapped_values['pack_size_text']);
        $this->assertSame('24.50', $raw->raw_values['Price']);
        $this->assertSame($this->organization->id, $raw->organization_id);
        $this->assertSame($id, $raw->import_run_id);
        $this->assertDatabaseCount('raw_import_records', 3);
        $this->assertDatabaseHas('import_errors', ['source_row' => 5, 'field' => 'description', 'code' => 'missing_description']);
        $this->getJson($this->url($id))->assertOk()->assertJsonPath('import.valid_rows', 3)->assertJsonPath('import.failed_count', 1)->assertJsonPath('errors.0.source_row', 5);
        $this->postJson($this->url($id.'/start'))->assertAccepted()->assertJsonPath('import.status', 'queued');
        Queue::assertPushedOn('import-normalization', AwaitImportNormalization::class);
        $this->app->call([new AwaitImportNormalization($this->organization->id, $id), 'handle']);
        $this->assertDatabaseHas('import_runs', ['id' => $id, 'status' => 'queued', 'processed_count' => 0]);
        $this->assertDatabaseCount('normalized_import_records', 0);
    }

    public function test_validation_replay_and_start_replay_do_not_duplicate_evidence(): void
    {
        $id = $this->mapped();
        $this->validateRun($id);
        $this->runJob($id);
        $this->postJson($this->url($id.'/validate'))->assertAccepted();
        $this->putJson($this->url($id.'/mapping'), $this->mapping())->assertConflict()->assertJsonPath('error.code', 'mapping_locked');
        $this->postJson($this->url($id.'/start'))->assertAccepted();
        $this->postJson($this->url($id.'/start'))->assertAccepted();
        $this->runJob($id);
        $this->assertDatabaseCount('raw_import_records', 3);
        $this->assertDatabaseCount('import_errors', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'import.queued')->count());
    }

    public function test_validation_runs_in_bounded_batches(): void
    {
        config(['imports.batch_rows' => 2]);
        $id = $this->mapped();
        $this->validateRun($id);
        $this->assertDatabaseCount('raw_import_records', 2);
        $this->assertNull(ImportRun::query()->findOrFail($id)->validation_completed_at);
        $this->postJson($this->url($id.'/start'))->assertConflict();
        $this->runJob($id);
        $this->assertDatabaseCount('raw_import_records', 3);
        $this->assertNotNull(ImportRun::query()->findOrFail($id)->validation_completed_at);
        Queue::assertPushed(ValidateImportBatch::class, 2);
    }

    public function test_invalid_transitions_and_no_valid_rows_are_rejected(): void
    {
        $id = $this->upload()->assertCreated()->json('import.id');
        $this->postJson($this->url($id.'/validate'))->assertConflict();
        $this->postJson($this->url($id.'/start'))->assertConflict();
        $this->postJson($this->url($id.'/cancel'))->assertOk();
        foreach (['validate', 'start'] as $action) { $this->postJson($this->url($id.'/'.$action))->assertConflict(); }
        $this->putJson($this->url($id.'/mapping'), $this->mapping())->assertConflict();
        $this->assertSame(ImportStatus::Canceled, ImportRun::query()->findOrFail($id)->status);
    }

    public function test_cancel_is_idempotent_and_stops_pending_jobs_without_deleting_raw(): void
    {
        config(['imports.batch_rows' => 2]);
        $id = $this->mapped();
        $this->validateRun($id);
        $this->postJson($this->url($id.'/cancel'))->assertOk();
        $this->postJson($this->url($id.'/cancel'))->assertOk();
        $this->runJob($id);
        $this->app->call([new AwaitImportNormalization($this->organization->id, $id), 'handle']);
        $this->assertDatabaseCount('raw_import_records', 2);
        $this->assertSame(1, AuditLog::query()->where('action', 'import.canceled')->count());
    }

    public function test_private_download_requires_current_auth_valid_signature_and_ancestry(): void
    {
        $id = $this->upload()->assertCreated()->json('import.id');
        $url = $this->postJson($this->url($id.'/downloads'))->assertOk()->json('file_url');
        $this->get($url)->assertOk()->assertHeader('content-type', 'application/octet-stream');
        $this->getJson(strtok($url, '?'))->assertForbidden();
        $other = Organization::factory()->create();
        $this->membership($this->user, $other, Role::Viewer);
        $forged = URL::temporarySignedRoute('imports.file', now()->addMinutes(5), ['organization' => $other->id, 'import' => $id, 'file' => ImportFile::query()->sole()->id], absolute: false);
        $this->getJson($forged)->assertNotFound();
        $this->asUser(User::factory()->create())->getJson($url)->assertForbidden();
        $this->asUser($this->user);
        $this->travel(6)->minutes();
        $this->getJson($url)->assertForbidden();
    }

    public function test_error_download_is_private_safe_csv(): void
    {
        $id = $this->mapped();
        $this->validateRun($id);
        $url = $this->postJson($this->url($id.'/downloads'))->assertOk()->json('errors_url');
        $response = $this->get($url)->assertOk();
        $this->assertStringContainsString('5,description,missing_description', $response->streamedContent());
        $this->assertStringNotContainsString('TOM BEEF', $response->streamedContent());
    }

    public function test_audit_has_actions_and_correlation_but_no_row_or_storage_secrets(): void
    {
        $id = $this->mapped();
        $this->validateRun($id);
        $this->postJson($this->url($id.'/start'))->assertAccepted();
        $this->postJson($this->url($id.'/cancel'))->assertOk();
        foreach (['created', 'mapping_confirmed', 'validation_started', 'queued', 'canceled'] as $action) {
            $audit = AuditLog::query()->where('action', 'import.'.$action)->sole();
            $this->assertSame($id, $audit->subject_id);
            $this->assertArrayHasKey('request_id', $audit->metadata);
            $this->assertStringNotContainsString('TOM BEEF', json_encode($audit->metadata));
            $this->assertStringNotContainsString('storage_key', json_encode($audit->metadata));
        }
    }

    public function test_late_structural_failure_is_safe_and_preserves_prior_valid_rows(): void
    {
        $content = "Item,Description,Brand,Pack,Price\n".str_repeat("1,Tomato,Pilot,CS,1\n", 21)."2,\"broken\n";
        $id = $this->upload(UploadedFile::fake()->createWithContent('late.csv', $content))->assertCreated()->json('import.id');
        $this->putJson($this->url($id.'/mapping'), $this->mapping())->assertOk();
        $this->validateRun($id);
        $this->assertDatabaseCount('raw_import_records', 21);
        $this->assertDatabaseHas('import_runs', ['id' => $id, 'status' => 'failed']);
        $this->assertDatabaseHas('import_errors', ['code' => 'invalid_import_file']);
        $this->postJson($this->url($id.'/start'))->assertConflict();
    }

    public function test_retry_exhaustion_does_not_leak_exception_or_change_canceled_state(): void
    {
        $id = $this->mapped();
        $this->postJson($this->url($id.'/validate'))->assertAccepted();
        $job = new ValidateImportBatch($this->organization->id, $id);
        $this->assertSame(3, $job->tries);
        $job->failed(new \RuntimeException('secret storage credentials'));
        $this->assertDatabaseHas('import_runs', ['id' => $id, 'status' => 'failed']);
        $this->assertStringNotContainsString('secret', ImportError::query()->sole()->message);
        $job->failed(null);
        $this->assertDatabaseCount('import_errors', 1);
    }

    #[DataProvider('unsafeXlsx')]
    public function test_xlsx_rejects_unsafe_structures(string $attack): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'import-test-');
        copy(base_path('tests/Fixtures/import-supplier-b.xlsx'), $temp);
        try {
            $zip = new ZipArchive;
            $zip->open($temp);
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            match ($attack) {
                'formula' => $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('<is>', '<f>1+1</f><is>', $sheet)),
                'truncated XML' => $zip->addFromString('xl/worksheets/sheet1.xml', substr($sheet, 0, -24)),
                'entity' => $zip->addFromString('xl/worksheets/sheet1.xml', '<!DOCTYPE worksheet [<!ENTITY x SYSTEM "file:///etc/passwd">]>'.$sheet),
                'external link' => $zip->addFromString('xl/externalLinks/link.xml', '<link/>'),
                'multiple sheets' => $zip->addFromString('xl/worksheets/sheet2.xml', $sheet),
                'expanded limit' => $zip->addFromString('xl/large.xml', str_repeat('x', 8388609)),
                default => null,
            };
            $zip->close();
            $this->upload(new UploadedFile($temp, 'unsafe.xlsx', test: true))->assertUnprocessable();
        } finally { unlink($temp); }
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeXlsx(): iterable
    {
        foreach (['formula', 'truncated XML', 'entity', 'external link', 'multiple sheets', 'expanded limit'] as $attack) { yield $attack => [$attack]; }
    }

    /** @param array<string, mixed> $extra */
    private function mapped(array $extra = []): string
    {
        $id = $this->upload()->assertCreated()->json('import.id');
        $this->putJson($this->url($id.'/mapping'), [...$this->mapping(), ...$extra])->assertOk();
        return $id;
    }

    /** @return array<string, mixed> */
    private function mapping(): array
    {
        return ['mapping' => ['supplier_sku' => 'Item', 'description' => 'Description', 'brand' => 'Brand', 'pack_size_text' => 'Pack', 'price' => 'Price']];
    }

    private function validateRun(string $id): void
    {
        $this->postJson($this->url($id.'/validate'))->assertAccepted();
        $this->runJob($id);
    }

    private function runJob(string $id): void { $this->app->call([new ValidateImportBatch($this->organization->id, $id), 'handle']); }

    /** @param array<string, mixed> $extra
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function upload(?UploadedFile $file = null, array $extra = []): TestResponse
    {
        return $this->postJson($this->url(), ['restaurant_location_id' => $this->account->restaurant_location_id, 'supplier_account_id' => $this->account->id, 'file' => $file ?? $this->fixture('a'), ...$extra]);
    }

    private function fixture(string $supplier): UploadedFile
    {
        $name = 'import-supplier-'.$supplier.($supplier === 'a' ? '.csv' : '.xlsx');
        return new UploadedFile(base_path('tests/Fixtures/'.$name), $name, test: true);
    }

    private function url(string $suffix = ''): string { return '/api/v1/organizations/'.$this->organization->id.'/imports'.($suffix === '' ? '' : '/'.$suffix); }
}
