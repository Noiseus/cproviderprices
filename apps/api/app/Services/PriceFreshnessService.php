<?php

namespace App\Services;

use App\Enums\PriceFreshness;
use App\Enums\SupplierRefreshCadence;
use App\Enums\SupplierSourceMethod;
use App\Models\PriceObservation;
use App\Models\SupplierAccount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Deterministic current-state classification; it never alters price evidence. */
final class PriceFreshnessService
{
    public function classify(SupplierAccount $account, PriceObservation $observation, ?CarbonImmutable $at = null): PriceFreshness
    {
        if ($account->source_method === SupplierSourceMethod::ManualImport) {
            return PriceFreshness::ManualImport;
        }

        if ($account->last_sync_failed_at !== null
            && ($account->last_successful_sync_at === null || $account->last_sync_failed_at->greaterThanOrEqualTo($account->last_successful_sync_at))) {
            return PriceFreshness::ConnectionFailed;
        }

        $hours = $this->cadenceHours($account->expected_refresh_cadence);
        // An automatic source without a configured expected cadence cannot be
        // represented as current. It is therefore explicitly stale, never live.
        if ($hours === null) {
            return PriceFreshness::Stale;
        }

        $now = ($at ?? now())->utc();
        $sourceTime = $observation->source_updated_at ?? $observation->observed_at;
        $age = $sourceTime->greaterThan($now) ? 0 : $sourceTime->diffInHours($now);
        if ($age <= $hours) {
            return PriceFreshness::Fresh;
        }
        if ($age <= $hours * 2) {
            return PriceFreshness::Aging;
        }

        return PriceFreshness::Stale;
    }

    public function nextExpectedAt(SupplierRefreshCadence $cadence, CarbonInterface $from): ?CarbonImmutable
    {
        $from = $from->toImmutable();

        return match ($cadence) {
            SupplierRefreshCadence::Daily => $from->addDay(),
            SupplierRefreshCadence::Weekly => $from->addWeek(),
            SupplierRefreshCadence::Monthly => $from->addDays(31),
            SupplierRefreshCadence::OnDemand => null,
        };
    }

    private function cadenceHours(SupplierRefreshCadence $cadence): ?int
    {
        return match ($cadence) {
            SupplierRefreshCadence::Daily => 24,
            SupplierRefreshCadence::Weekly => 24 * 7,
            SupplierRefreshCadence::Monthly => 24 * 31,
            SupplierRefreshCadence::OnDemand => null,
        };
    }
}
