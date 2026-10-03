<?php

namespace Tests\Concerns;

use App\Enums\ImportStatus;
use App\Jobs\NormalizeImportBatch;
use App\Models\ImportFile;
use App\Models\ImportRun;
use App\Models\RawImportRecord;
use App\Models\SupplierAccount;
use App\Models\User;
use Illuminate\Support\Str;

trait BuildsNormalizationImport
{
    private function normalizationRun(int $count = 1, ImportStatus $status = ImportStatus::Processing): ImportRun
    {
        $account = SupplierAccount::factory()->create();
        $run = ImportRun::query()->create(['organization_id' => $account->organization_id, 'restaurant_location_id' => $account->restaurant_location_id,
            'supplier_account_id' => $account->id, 'created_by' => User::factory()->create()->id, 'fingerprint' => hash('sha256', Str::uuid()),
            'request_id' => 'phase-d-'.Str::uuid(), 'status' => $status,
            'mapping_snapshot' => ['version' => 1, 'mapping' => ['supplier_sku' => 'SKU', 'description' => 'Description'], 'source_conventions' => ['produce_size_code' => 1]]]);
        $run->validation_started_at = now();
        $run->validation_completed_at = now();
        $run->save();
        $file = ImportFile::query()->create(['organization_id' => $run->organization_id, 'import_run_id' => $run->id, 'original_filename' => 'synthetic.csv',
            'storage_key' => 'phase-d/'.Str::uuid(), 'size_bytes' => 10, 'media_type' => 'text/csv', 'format' => 'csv']);
        for ($i = 0; $i < $count; $i++) {
            RawImportRecord::query()->create(['organization_id' => $run->organization_id, 'import_run_id' => $run->id, 'import_file_id' => $file->id,
                'source_row' => $i + 2, 'raw_values' => ['SKU' => '00012', 'Description' => 'MOZZ WHOLE MILK 4/5 LB'],
                'mapped_values' => ['supplier_sku' => '00012', 'description' => 'MOZZ WHOLE MILK 4/5 LB'], 'status' => 'pending']);
        }

        return $run;
    }

    private function normalizationJob(ImportRun $run): NormalizeImportBatch
    {
        return new NormalizeImportBatch($run->organization_id, $run->id, RawImportRecord::query()->where('import_run_id', $run->id)->orderBy('source_row')->limit(25)->pluck('id')->all());
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function normalizationResponse(array $request, bool $warning = false): array
    {
        $results = [];
        foreach ($request['records'] as $record) {
            $results[] = ['parser_version' => '1.0.0', 'record_id' => $record['record_id'], 'correlation_id' => $record['correlation_id'],
                'original' => $record, 'measurements' => [], 'packs' => [], 'case_quantity' => null, 'size_code' => null, 'variable_weight' => false,
                'warnings' => $warning ? [['code' => 'missing_quantity', 'field' => 'quantity', 'message' => 'No safe quantity.']] : [], 'confidence' => $warning ? 'low' : 'high'];
        }

        return ['parser_version' => '1.0.0', 'correlation_id' => $request['correlation_id'], 'results' => $results, 'processed' => count($results), 'warning' => $warning ? count($results) : 0, 'failed' => 0];
    }
}
