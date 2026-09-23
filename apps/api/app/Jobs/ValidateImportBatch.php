<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\ImportError;
use App\Models\ImportRun;
use App\Models\Organization;
use App\Models\RawImportRecord;
use App\Services\ImportAccess;
use App\Services\ImportFileReader;
use App\Services\ImportLifecycleService;
use App\Support\ApiProblem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ValidateImportBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 60;
    public bool $failOnTimeout = true;

    public function __construct(public string $organizationId, public string $importId) {}

    /** @return list<int> */
    public function backoff(): array { return [5, 15, 30]; }

    public function handle(ImportAccess $access, ImportFileReader $reader): void
    {
        $organization = Organization::query()->find($this->organizationId);
        if ($organization === null) { return; }
        DB::transaction(function () use ($organization, $access, $reader): void {
            // Serializes duplicate deliveries and cancellation; a canceled run never writes rows.
            $run = $access->run($organization, $this->importId, true);
            if ($run->status !== ImportStatus::NeedsMapping || $run->validation_started_at === null || $run->validation_completed_at !== null) { return; }
            $file = $access->file($run);
            $snapshot = $run->mapping_snapshot;
            if ($snapshot === null) { return; }
            $temporary = tmpfile();
            $source = Storage::disk('s3')->readStream($file->storage_key);
            if ($temporary === false || ! is_resource($source)) {
                if (is_resource($temporary)) { fclose($temporary); }
                if (is_resource($source)) { fclose($source); }
                throw new RuntimeException('Import storage is unavailable.');
            }
            try {
                $size = stream_copy_to_stream($source, $temporary, 10485761);
                if ($size !== $file->size_bytes || $size > 10485760) {
                    throw new ApiProblem('invalid_import_file', 'The stored file does not match the uploaded evidence.', 422);
                }
                $path = stream_get_meta_data($temporary)['uri'];
                if (hash_file('sha256', $path) !== $run->fingerprint) {
                    throw new ApiProblem('invalid_import_file', 'The stored file does not match the uploaded evidence.', 422);
                }
                $count = 0;
                $more = false;
                foreach ($reader->rows($path, $file->format) as $row => $cells) {
                    if ($row <= $run->validation_cursor) { continue; }
                    if ($count >= config('imports.batch_rows')) { $more = true; break; }
                    $count++;
                    $run->validation_cursor = $row;
                    $headers = $snapshot['headers'];
                    if (count($cells) !== count($headers)) {
                        $this->rowError($run, $row, null, 'column_count_mismatch', 'The row must have the same number of cells as the header.');
                        $run->failed_count++;
                        continue;
                    }
                    $raw = array_combine($headers, $cells);
                    $mapped = [];
                    foreach ($snapshot['mapping'] as $field => $column) { $mapped[$field] = $raw[$column]; }
                    $invalid = false;
                    foreach (['supplier_sku', 'description'] as $required) {
                        if (trim($mapped[$required] ?? '') === '') {
                            $this->rowError($run, $row, $required, 'missing_'.$required, 'A value is required for this mapped field.');
                            $invalid = true;
                        }
                    }
                    if ($invalid) { $run->failed_count++; continue; }
                    RawImportRecord::query()->firstOrCreate([
                        'organization_id' => $run->organization_id, 'import_run_id' => $run->id, 'import_file_id' => $file->id, 'source_row' => $row,
                    ], ['raw_values' => $raw, 'mapped_values' => $mapped, 'status' => 'pending']);
                }
                if (! $more) { $run->validation_completed_at = now(); }
                $run->save();
                if ($more) { self::dispatch($run->organization_id, $run->id)->onConnection('redis')->afterCommit(); }
            } catch (ApiProblem $error) {
                $this->rowError($run, null, null, 'invalid_import_file', 'The file is structurally invalid or exceeds the supported limits.');
                $run->save();
                app(ImportLifecycleService::class)->transition($organization, $run->id, ImportStatus::Failed);
            } finally {
                fclose($source);
                fclose($temporary);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $run = ImportRun::query()->where('organization_id', $this->organizationId)->whereKey($this->importId)->lockForUpdate()->first();
            if ($run === null || $run->status !== ImportStatus::NeedsMapping || $run->validation_completed_at !== null) { return; }
            $this->rowError($run, null, null, 'validation_unavailable', 'Validation could not finish after bounded retries.');
            app(ImportLifecycleService::class)->transition($run->organization, $run->id, ImportStatus::Failed);
        });
    }

    private function rowError(ImportRun $run, ?int $row, ?string $field, string $code, string $message): void
    {
        ImportError::query()->firstOrCreate(['organization_id' => $run->organization_id, 'import_run_id' => $run->id,
            'error_key' => ($row ?? 'file').':'.$code], ['source_row' => $row, 'field' => $field, 'code' => $code, 'message' => $message]);
    }
}
