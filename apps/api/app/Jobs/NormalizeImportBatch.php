<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\ImportRun;
use App\Models\NormalizedImportRecord;
use App\Models\Organization;
use App\Services\ImportAccess;
use App\Services\ImportNormalizationProgress;
use App\Services\ImportNormalizer;
use App\Support\NormalizationFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class NormalizeImportBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    /** @param list<string> $rawIds */
    public function __construct(public string $organizationId, public string $importId, public array $rawIds) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(ImportAccess $access, ImportNormalizer $normalizer, ImportNormalizationProgress $progress): void
    {
        try {
            $failure = DB::transaction(function () use ($access, $normalizer, $progress): ?NormalizationFailure {
                $organization = Organization::query()->find($this->organizationId);
                if ($organization === null) {
                    return null;
                }
                $run = $access->run($organization, $this->importId, true);
                if ($run->status !== ImportStatus::Processing) {
                    return null;
                }
                $file = $access->file($run);
                if (count($this->rawIds) > 25 || $this->rawIds === []) {
                    throw new NormalizationFailure('invalid_batch_references');
                }
                $rows = $progress->remaining($run)->where('import_file_id', $file->id)->whereIn('id', $this->rawIds)->orderBy('source_row')->get();
                if ($rows->isEmpty()) {
                    $this->continue($run, $progress);

                    return null;
                }
                try {
                    $records = $rows->map(fn ($raw) => $normalizer->record($run, $raw))->all();
                    $results = $normalizer->normalize($run->request_id, $records);
                } catch (NormalizationFailure $error) {
                    if ($error->transient && $this->attempts() < $this->tries) {
                        $run->increment('retry_count');
                        Log::warning('import.normalization.retry', ['request_id' => $run->request_id, 'import_id' => $run->id, 'retry' => $run->retry_count, 'attempt' => $this->attempts(), 'code' => $error->reason]);
                    } else {
                        foreach ($rows as $raw) {
                            $progress->failRow($run, $raw, $error->transient ? 'service_retries_exhausted' : $error->reason);
                        }
                        $this->continue($run, $progress);
                    }

                    return $error;
                }
                foreach ($results as $result) {
                    $raw = $rows->firstWhere('id', $result['record_id']);
                    NormalizedImportRecord::query()->firstOrCreate([
                        'organization_id' => $run->organization_id, 'import_run_id' => $run->id,
                        'raw_import_record_id' => $raw->id, 'parser_version' => ImportNormalizer::VERSION,
                    ], ['normalized_values' => $result, 'warnings' => $result['warnings'], 'confidence' => $result['confidence']]);
                    $raw->update(['status' => 'normalized']);
                }
                $this->continue($run, $progress);

                return null;
            });
        } catch (Throwable) {
            // Discard SQL bindings, client exception chains and all untrusted response text.
            throw new NormalizationFailure('processing_unavailable', true);
        }
        if ($failure !== null) {
            if ($failure->transient && $this->attempts() < $this->tries) {
                throw $failure;
            }
            $this->fail($failure);
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            DB::transaction(function (): void {
                $run = ImportRun::query()->where('organization_id', $this->organizationId)->whereKey($this->importId)->lockForUpdate()->first();
                if ($run === null || $run->status !== ImportStatus::Processing) {
                    return;
                }
                $progress = app(ImportNormalizationProgress::class);
                $rows = $progress->remaining($run)->whereIn('id', array_slice($this->rawIds, 0, 25))->get();
                if ($rows->isEmpty()) {
                    return;
                }
                foreach ($rows as $raw) {
                    $progress->failRow($run, $raw, 'processing_retries_exhausted');
                }
                $this->continue($run, $progress);
            });
        } catch (Throwable) {
            throw new NormalizationFailure('failure_recording_unavailable');
        }
    }

    private function continue(ImportRun $run, ImportNormalizationProgress $progress): void
    {
        $progress->update($run);
        if ($progress->remaining($run)->exists()) {
            AwaitImportNormalization::dispatch($run->organization_id, $run->id)->onConnection('redis')->onQueue('import-normalization')->afterCommit();
        }
    }
}
