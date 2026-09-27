<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Organization;
use App\Services\ImportAccess;
use App\Services\ImportLifecycleService;
use App\Services\ImportNormalizationProgress;
use App\Support\NormalizationFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Backward-compatible Phase B pointer: discover one bounded remaining batch. */
final class AwaitImportNormalization implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function __construct(public string $organizationId, public string $importId) {}

    public function handle(ImportAccess $access): void
    {
        $organization = Organization::query()->find($this->organizationId);
        if ($organization === null) {
            return;
        }
        try {
            DB::transaction(function () use ($organization, $access): void {
                $run = $access->run($organization, $this->importId, true);
                if (! in_array($run->status, [ImportStatus::Queued, ImportStatus::Processing], true)) {
                    return;
                }
                $access->file($run);
                if ($run->status === ImportStatus::Queued) {
                    $run = app(ImportLifecycleService::class)->transition($organization, $run->id, ImportStatus::Processing);
                }
                $progress = app(ImportNormalizationProgress::class);
                $ids = [];
                $bytes = 1024;
                foreach ($progress->remaining($run)->orderBy('source_row')->limit(min(25, max(1, (int) config('imports.normalization_batch_rows'))))->get() as $raw) {
                    $size = strlen(json_encode($raw->mapped_values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') + 2048;
                    if ($ids !== [] && $bytes + $size > 900000) {
                        break;
                    }
                    $bytes += $size;
                    $ids[] = $raw->id;
                }
                $progress->update($run);
                if ($ids !== []) {
                    NormalizeImportBatch::dispatch($run->organization_id, $run->id, $ids)->onConnection('redis')->onQueue('import-normalization')->afterCommit();
                }
            });
        } catch (\Throwable) {
            throw new NormalizationFailure('dispatch_unavailable', true);
        }
    }
}
