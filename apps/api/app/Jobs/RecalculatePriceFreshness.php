<?php

namespace App\Jobs;

use App\Models\SupplierOffer;
use App\Services\PriceFreshnessService;
use App\Services\SearchComparisonCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Bounded, idempotent derived-state refresh; immutable observations are never changed. */
final class RecalculatePriceFreshness implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    private const BATCH_SIZE = 100;

    public function handle(PriceFreshnessService $freshness, SearchComparisonCache $cache): void
    {
        $lock = Cache::lock('price-freshness:recalculate', 300);
        if (! $lock->get()) {
            Log::info('freshness.recalculation.skipped', ['event' => 'freshness.recalculation.skipped', 'reason' => 'lock_unavailable']);

            return;
        }
        $startedAt = hrtime(true);
        try {
            $offers = SupplierOffer::query()
                ->whereNotNull('current_price_observation_id')
                ->where(function ($query): void {
                    $query->whereNull('freshness_calculated_at')
                        ->orWhere('freshness_calculated_at', '<', now()->subMinutes(5));
                })
                ->with(['currentPriceObservation', 'supplierAccount', 'vendorItem.currentMapping'])
                ->orderBy('freshness_calculated_at')
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->get();
            $changed = [];
            foreach ($offers as $offer) {
                if ($offer->currentPriceObservation === null) {
                    continue;
                }
                $state = $freshness->classify($offer->supplierAccount, $offer->currentPriceObservation);
                if ($offer->freshness_state !== $state) {
                    $changed[$offer->organization_id][$offer->restaurant_location_id][] = $offer->vendorItem->currentMapping?->canonical_product_id;
                }
                $offer->forceFill([
                    'freshness_state' => $state,
                    'freshness_calculated_at' => now(),
                ])->save();
            }
            foreach ($changed as $organizationId => $locations) {
                foreach ($locations as $locationId => $productIds) {
                    $cache->invalidate(
                        $organizationId,
                        $locationId,
                        array_values(array_filter($productIds, 'is_string')),
                    );
                }
            }
            Log::info('freshness.recalculation.completed', [
                'event' => 'freshness.recalculation.completed',
                'offers_examined' => $offers->count(),
                'offers_changed' => array_sum(array_map(
                    static fn (array $locations): int => array_sum(array_map('count', $locations)),
                    $changed,
                )),
                'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            ]);
        } finally {
            $lock->release();
        }
    }
}
