<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Models\ImportError;
use App\Models\ImportFile;
use App\Models\ImportMappingTemplate;
use App\Models\ImportRun;
use App\Models\NormalizedImportRecord;
use App\Models\Organization;
use App\Models\RawImportRecord;
use App\Models\SupplierAccount;
use App\Models\User;
use App\Services\ImportLifecycleService;
use App\Support\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ImportPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_preserves_source_and_versioned_traceability(): void
    {
        $run = $this->runRecord();
        $file = $this->fileRecord($run);
        $raw = $this->rawRecord($file);
        $result = $this->normalizedRecord($raw);
        $secondVersion = $this->normalizedRecord($raw, ['parser_version' => '2.0.0']);

        $this->assertSame('7', $run->id[14]);
        $this->assertSame(ImportStatus::Uploaded, $run->status);
        $this->assertSame(['SKU' => '00012', 'Description' => 'MOZZ WHOLE MILK 4/5 LB'], $raw->refresh()->raw_values);
        $this->assertSame(2, $raw->source_row);
        $this->assertTrue($result->rawImportRecord->is($raw));
        $this->assertTrue($raw->importFile->is($file));
        $this->assertTrue($file->importRun->is($run));
        $this->assertTrue($run->supplierAccount->is($raw->importRun->supplierAccount));
        $this->assertSame($run->organization_id, $result->organization_id);
        $this->assertNotSame($result->id, $secondVersion->id);
        $this->assertArrayNotHasKey('storage_key', $file->toArray());
        $this->assertArrayNotHasKey('raw_values', $raw->toArray());
        $this->assertSame(2, NormalizedImportRecord::query()->count());
    }

    public function test_duplicate_fingerprint_is_scoped_to_supplier_account(): void
    {
        $run = $this->runRecord();
        $other = $this->runRecord();
        $this->assertNotSame($run->supplier_account_id, $other->supplier_account_id);
        $this->assertSame($run->fingerprint, $other->fingerprint);
        $this->expectException(QueryException::class);
        $this->runRecord($run->supplierAccount);
    }

    #[DataProvider('scopeAttacks')]
    public function test_database_rejects_forged_ancestry(string $attack): void
    {
        $run = $this->runRecord();
        $other = $this->runRecord();
        $file = $this->fileRecord($run);
        $raw = $this->rawRecord($file);
        $template = ImportMappingTemplate::query()->create([
            'organization_id' => $other->organization_id,
            'supplier_account_id' => $other->supplier_account_id,
            'name' => 'Alternate columns', 'headers' => ['SKU'],
            'mapping' => ['supplier_sku' => 'SKU'], 'source_conventions' => [],
        ]);

        $this->expectException(QueryException::class);
        match ($attack) {
            'run tenant' => $run->update(['organization_id' => $other->organization_id]),
            'run location' => $run->update(['restaurant_location_id' => $other->restaurant_location_id]),
            'run account' => $run->update(['supplier_account_id' => $other->supplier_account_id]),
            'mapping account' => $run->update(['mapping_template_id' => $template->id]),
            'file tenant' => $file->update(['organization_id' => $other->organization_id]),
            'raw file' => RawImportRecord::query()->whereKey($raw->id)->update(['import_file_id' => $this->fileRecord($other)->id]),
            'raw run' => RawImportRecord::query()->whereKey($raw->id)->update(['import_run_id' => $other->id]),
            'normalized run' => $this->normalizedRecord($raw, ['import_run_id' => $other->id]),
            'error tenant' => ImportError::query()->create([
                'organization_id' => $other->organization_id, 'import_run_id' => $run->id,
                'code' => 'missing_description', 'field' => 'description', 'source_row' => 2,
                'message' => 'Description is required.', 'error_key' => 'row:2:description',
            ]),
            default => throw new \LogicException('Unknown test case'),
        };
    }

    /** @return iterable<string, array{string}> */
    public static function scopeAttacks(): iterable
    {
        foreach (['run tenant', 'run location', 'run account', 'mapping account', 'file tenant', 'raw file', 'raw run', 'normalized run', 'error tenant'] as $attack) {
            yield $attack => [$attack];
        }
    }

    public function test_raw_replay_cannot_duplicate_source_row(): void
    {
        $file = $this->fileRecord($this->runRecord());
        $this->rawRecord($file);
        $this->expectException(QueryException::class);
        $this->rawRecord($file);
    }

    public function test_normalization_replay_cannot_duplicate_parser_version(): void
    {
        $raw = $this->rawRecord($this->fileRecord($this->runRecord()));
        $this->normalizedRecord($raw);
        $this->expectException(QueryException::class);
        $this->normalizedRecord($raw);
    }

    public function test_mapping_snapshot_survives_template_change(): void
    {
        $run = $this->runRecord();
        $template = ImportMappingTemplate::query()->create([
            'organization_id' => $run->organization_id, 'supplier_account_id' => $run->supplier_account_id,
            'name' => 'CSV v1', 'headers' => ['SKU'], 'mapping' => ['supplier_sku' => 'SKU'],
            'source_conventions' => ['volume_system' => 'us_liquid'],
        ]);
        $run->update(['mapping_template_id' => $template->id, 'mapping_snapshot' => [
            'mapping' => $template->mapping, 'source_conventions' => $template->source_conventions,
        ]]);
        $template->update(['mapping' => ['supplier_sku' => 'Item']]);
        $this->assertSame([
            'mapping' => ['supplier_sku' => 'SKU'],
            'source_conventions' => ['volume_system' => 'us_liquid'],
        ], $run->refresh()->mapping_snapshot);
        $this->assertTrue($run->mappingTemplate->is($template));
    }

    public function test_lifecycle_preserves_records_and_terminal_states(): void
    {
        $run = $this->runRecord();
        $service = new ImportLifecycleService;
        foreach ([ImportStatus::Validating, ImportStatus::NeedsMapping, ImportStatus::Queued, ImportStatus::Processing, ImportStatus::PartiallyCompleted] as $status) {
            $run = $service->transition($run->organization, $run->id, $status);
            $this->assertSame($status, $run->status);
        }
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertDatabaseCount('import_runs', 1);
        $this->expectException(ApiProblem::class);
        $service->transition($run->organization, $run->id, ImportStatus::Queued);
    }

    public function test_invalid_transition_does_not_change_run(): void
    {
        $run = $this->runRecord();
        try {
            (new ImportLifecycleService)->transition($run->organization, $run->id, ImportStatus::Completed);
            $this->fail('Invalid transition was accepted.');
        } catch (ApiProblem) {
            $this->assertSame(ImportStatus::Uploaded, $run->refresh()->status);
        }
    }

    public function test_cancellation_is_terminal_and_tenant_scoped(): void
    {
        $run = $this->runRecord();
        $service = new ImportLifecycleService;
        try {
            $service->transition(Organization::factory()->create(), $run->id, ImportStatus::Canceled);
            $this->fail('Foreign tenant accepted.');
        } catch (ApiProblem) {
            $this->assertSame(ImportStatus::Uploaded, $run->refresh()->status);
        }
        $service->transition($run->organization, $run->id, ImportStatus::Canceled);
        $this->assertTrue($run->refresh()->status->isTerminal());
        $this->assertNotNull($run->finished_at);
    }

    public function test_database_rejects_negative_counters(): void
    {
        $run = $this->runRecord();
        $this->expectException(QueryException::class);
        ImportRun::query()->whereKey($run->id)->update(['processed_count' => -1]);
    }

    public function test_source_evidence_is_immutable_but_processing_status_can_change(): void
    {
        $raw = $this->rawRecord($this->fileRecord($this->runRecord()));
        $raw->update(['status' => 'normalized']);
        $this->assertSame('normalized', $raw->refresh()->status);
        $this->expectException(\LogicException::class);
        $raw->update(['raw_values' => ['replaced' => true]]);
    }

    public function test_existing_normalized_output_cannot_be_overwritten(): void
    {
        $raw = $this->rawRecord($this->fileRecord($this->runRecord()));
        $result = $this->normalizedRecord($raw);
        $this->expectException(\LogicException::class);
        $result->update(['normalized_values' => ['invented' => true]]);
    }

    public function test_database_rejects_unknown_lifecycle_status(): void
    {
        $run = $this->runRecord();
        $this->expectException(QueryException::class);
        ImportRun::query()->whereKey($run->id)->update(['status' => 'invented']);
    }

    private function runRecord(?SupplierAccount $account = null): ImportRun
    {
        $account ??= SupplierAccount::factory()->create();

        return ImportRun::query()->create([
            'organization_id' => $account->organization_id,
            'restaurant_location_id' => $account->restaurant_location_id,
            'supplier_account_id' => $account->id, 'created_by' => User::factory()->create()->id,
            'fingerprint' => hash('sha256', 'deterministic fixture'), 'request_id' => 'import-test',
            'status' => ImportStatus::Uploaded,
        ]);
    }

    private function fileRecord(ImportRun $run): ImportFile
    {
        return ImportFile::query()->create([
            'organization_id' => $run->organization_id, 'import_run_id' => $run->id,
            'original_filename' => 'supplier.csv', 'storage_key' => "imports/{$run->organization_id}/{$run->id}/source",
            'size_bytes' => 100, 'media_type' => 'text/csv', 'format' => 'csv',
        ]);
    }

    private function rawRecord(ImportFile $file): RawImportRecord
    {
        return RawImportRecord::query()->create([
            'organization_id' => $file->organization_id, 'import_run_id' => $file->import_run_id,
            'import_file_id' => $file->id, 'source_row' => 2,
            'raw_values' => ['SKU' => '00012', 'Description' => 'MOZZ WHOLE MILK 4/5 LB'],
            'mapped_values' => ['supplier_sku' => '00012', 'description' => 'MOZZ WHOLE MILK 4/5 LB'],
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function normalizedRecord(RawImportRecord $raw, array $overrides = []): NormalizedImportRecord
    {
        return NormalizedImportRecord::query()->create([
            'organization_id' => $raw->organization_id, 'import_run_id' => $raw->import_run_id,
            'raw_import_record_id' => $raw->id, 'parser_version' => '1.0.0',
            'normalized_values' => ['case_total' => '9071.8474', 'unit' => 'g'],
            'warnings' => [], 'confidence' => 'high', ...$overrides,
        ]);
    }
}
