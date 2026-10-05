<?php

namespace App\Listeners;

use App\Events\ImportPriceObservationsCompleted;
use App\Models\ImportRun;
use App\Models\PriceObservation;
use App\Models\SupplierAccount;
use App\Services\PriceFreshnessService;
use App\Services\SearchComparisonCache;

/** Applies post-import freshness metadata and invalidates only the affected scope. */
final class RefreshImportPriceState
{
    public function __construct(
        private readonly PriceFreshnessService $freshness,
        private readonly SearchComparisonCache $cache,
    ) {}

    public function handle(ImportPriceObservationsCompleted $event): void
    {
        $run = ImportRun::query()
            ->where('organization_id', $event->organizationId)
            ->where('restaurant_location_id', $event->locationId)
            ->whereKey($event->importRunId)
            ->first();
        if (! $run instanceof ImportRun) {
            return;
        }

        $account = SupplierAccount::query()
            ->where('organization_id', $run->organization_id)
            ->where('restaurant_location_id', $run->restaurant_location_id)
            ->whereKey($run->supplier_account_id)
            ->first();
        if ($account instanceof SupplierAccount) {
            $finishedAt = $run->finished_at ?? now()->toImmutable();
            $account->forceFill([
                'last_successful_sync_at' => $finishedAt,
                'next_expected_sync_at' => $this->freshness->nextExpectedAt($account->expected_refresh_cadence, $finishedAt),
                'last_sync_failed_at' => null,
                'last_sync_failure_code' => null,
            ])->save();
        }

        $observations = PriceObservation::query()
            ->where('organization_id', $run->organization_id)
            ->where('import_run_id', $run->id)
            ->with('supplierOffer.vendorItem.currentMapping')
            ->get();
        if ($observations->isEmpty()) {
            return;
        }

        $productIds = $observations->map(function (PriceObservation $observation): ?string {
            return $observation->supplierOffer?->vendorItem?->currentMapping?->canonical_product_id;
        })->filter(static fn (mixed $id): bool => is_string($id))->unique()->values()->all();

        // Search is location scoped and could include any updated offer;
        // comparison keys are additionally narrowed when a current mapping exists.
        $this->cache->invalidate($run->organization_id, $run->restaurant_location_id, $productIds);
    }
}
