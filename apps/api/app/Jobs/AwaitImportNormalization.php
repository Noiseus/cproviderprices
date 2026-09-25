<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Organization;
use App\Services\ImportAccess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Explicit Phase B handoff. The default worker does NOT consume import-normalization. */
final class AwaitImportNormalization implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $organizationId, public string $importId) {}

    public function handle(ImportAccess $access): void
    {
        $organization = Organization::query()->find($this->organizationId);
        if ($organization === null) {
            return;
        }
        DB::transaction(function () use ($organization, $access): void {
            $run = $access->run($organization, $this->importId, true);
            if ($run->status !== ImportStatus::Queued) {
                return;
            }
            $access->file($run);
            // Intentionally no normalization, processing/completed transition, or raw mutation.
            // Phase D replaces this handoff with bounded raw-ID batching and Python delivery.
        });
    }
}
