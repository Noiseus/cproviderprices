<?php

namespace App\Jobs;

use App\Models\NormalizedImportRecord;
use App\Services\CatalogMatcher;
use App\Services\VendorItemProjectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProjectVendorItemMatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function __construct(public string $normalizedImportRecordId) {}

    public function handle(VendorItemProjectionService $projection, CatalogMatcher $matcher): void
    {
        $normalized = NormalizedImportRecord::query()->find($this->normalizedImportRecordId);
        if ($normalized === null) {
            return;
        }
        $matcher->generate($projection->project($normalized));
    }
}
