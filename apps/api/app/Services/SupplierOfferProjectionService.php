<?php

namespace App\Services;

use App\Enums\OfferAvailability;
use App\Models\NormalizedImportRecord;
use App\Models\PriceObservation;
use App\Models\SupplierOffer;
use App\Models\VendorItem;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Projects a tenant-private logical offer and, only when the import supplies
 * sufficient unambiguous evidence, creates an immutable price observation.
 */
final class SupplierOfferProjectionService
{
    public const FORMULA_VERSION = 'price-comparison-1.0.0';

    /** @var list<string> */
    private const UNSAFE_QUANTITY_WARNINGS = [
        'missing_quantity',
        'ambiguous_quantity',
        'ambiguous_unit',
        'ambiguous_pack',
        'unit_system_required',
        'unsupported_unit',
        'unsafe_conversion',
        'conflicting_values',
        'variable_weight',
    ];

    public function __construct(private readonly SearchComparisonCache $cache) {}

    public function project(NormalizedImportRecord $normalized, VendorItem $vendor): SupplierOffer
    {
        /** @var array{offer: SupplierOffer, changed: bool} $result */
        $result = DB::transaction(function () use ($normalized, $vendor): array {
            $normalized->loadMissing('rawImportRecord.importRun');
            $raw = $normalized->rawImportRecord;
            $run = $raw->importRun;
            if ($vendor->organization_id !== $normalized->organization_id
                || $vendor->supplier_account_id !== $run->supplier_account_id) {
                throw new \LogicException('Offer projection ancestry does not match its normalized record.');
            }

            $offer = SupplierOffer::query()
                ->where('organization_id', $normalized->organization_id)
                ->where('restaurant_location_id', $run->restaurant_location_id)
                ->where('supplier_account_id', $run->supplier_account_id)
                ->where('vendor_item_id', $vendor->id)
                ->lockForUpdate()
                ->first();
            if (! $offer instanceof SupplierOffer) {
                try {
                    $offer = SupplierOffer::query()->create([
                        'organization_id' => $normalized->organization_id,
                        'restaurant_location_id' => $run->restaurant_location_id,
                        'supplier_account_id' => $run->supplier_account_id,
                        'vendor_item_id' => $vendor->id,
                        'current_price_observation_id' => null,
                        'is_active' => true,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    $offer = SupplierOffer::query()
                        ->where('organization_id', $normalized->organization_id)
                        ->where('restaurant_location_id', $run->restaurant_location_id)
                        ->where('supplier_account_id', $run->supplier_account_id)
                        ->where('vendor_item_id', $vendor->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                }
            }

            $price = $this->price($raw->mapped_values['price'] ?? null);
            $currency = $this->currency($raw->mapped_values['currency'] ?? null);
            $observedAt = $this->observedAt($raw->mapped_values['observed_at'] ?? null);
            if ($price === null || $currency === null || $observedAt === null) {
                return ['offer' => $offer, 'changed' => false];
            }

            $observation = PriceObservation::query()
                ->where('normalized_import_record_id', $normalized->id)
                ->first();
            $createdObservation = false;
            if ($observation instanceof PriceObservation
                && ($observation->organization_id !== $normalized->organization_id || $observation->supplier_offer_id !== $offer->id)) {
                throw new \LogicException('Price observation ancestry does not match its logical offer.');
            }
            if (! $observation instanceof PriceObservation) {
                $quantity = $this->quantity($normalized);
                $observation = PriceObservation::query()->create([
                    'organization_id' => $normalized->organization_id,
                    'supplier_offer_id' => $offer->id,
                    'import_run_id' => $run->id,
                    'normalized_import_record_id' => $normalized->id,
                    'case_price' => $price,
                    'currency' => $currency,
                    'source_price_evidence' => [
                        'source_price' => $raw->mapped_values['price'] ?? null,
                        'source_currency' => $raw->mapped_values['currency'] ?? null,
                        'source_observed_at' => $raw->mapped_values['observed_at'] ?? null,
                        'source_availability' => $raw->mapped_values['availability'] ?? null,
                    ],
                    'source_pack_evidence' => [
                        'packs' => $normalized->normalized_values['packs'] ?? [],
                        'measurements' => $normalized->normalized_values['measurements'] ?? [],
                        'case_quantity' => $normalized->normalized_values['case_quantity'] ?? null,
                        'variable_weight' => (bool) ($normalized->normalized_values['variable_weight'] ?? false),
                        'warnings' => $normalized->warnings,
                    ],
                    'normalized_quantity' => $quantity['value'] ?? null,
                    'normalized_unit' => $quantity['unit'] ?? null,
                    'dimension' => $quantity['dimension'] ?? null,
                    'availability' => $this->availability($raw->mapped_values['availability'] ?? null),
                    'observed_at' => $observedAt,
                    'formula_version' => self::FORMULA_VERSION,
                ]);
                $createdObservation = true;
            }

            $offer->loadMissing('currentPriceObservation');
            $current = $offer->currentPriceObservation;
            $currentChanged = ! ($current instanceof PriceObservation) || $this->isNewer($observation, $current);
            if ($currentChanged) {
                $offer->update(['current_price_observation_id' => $observation->id]);
            }

            return ['offer' => $offer->refresh(), 'changed' => $createdObservation || $currentChanged];
        });
        if ($result['changed']) {
            $this->cache->invalidate($result['offer']->organization_id, $result['offer']->restaurant_location_id);
        }

        return $result['offer'];
    }

    private function price(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (! preg_match('/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/D', $value)) {
            return null;
        }

        try {
            $price = BigDecimal::of($value);

            return $price->isNegative() ? null : (string) $price->toScale(6, RoundingMode::UNNECESSARY);
        } catch (MathException) {
            return null;
        }
    }

    private function currency(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $currency = strtoupper(trim($value));

        return preg_match('/\A[A-Z]{3}\z/D', $currency) === 1 ? $currency : null;
    }

    private function observedAt(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D', $value)) {
            return null;
        }
        $source = ! str_ends_with($value, 'Z') ? $value : substr($value, 0, -1).'+00:00';
        $parsed = CarbonImmutable::createFromFormat(\DateTimeInterface::ATOM, $source);
        $errors = CarbonImmutable::getLastErrors();
        if ($parsed === null || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $parsed->utc();
    }

    private function availability(mixed $value): OfferAvailability
    {
        if (! is_string($value)) {
            return OfferAvailability::Unknown;
        }

        return match (strtolower(trim($value))) {
            OfferAvailability::Available->value => OfferAvailability::Available,
            OfferAvailability::Unavailable->value => OfferAvailability::Unavailable,
            default => OfferAvailability::Unknown,
        };
    }

    /** @return array{value: string, unit: string, dimension: string}|array{} */
    private function quantity(NormalizedImportRecord $normalized): array
    {
        /** @var array<string, mixed> $values */
        $values = $normalized->normalized_values;
        if (($values['variable_weight'] ?? false) === true || $this->hasUnsafeWarning($normalized)) {
            return [];
        }
        $safe = [];
        foreach (($values['packs'] ?? []) as $pack) {
            if (! is_array($pack)
                || ! is_string($pack['case_total'] ?? null)
                || ! in_array($pack['case_total_unit'] ?? null, ['g', 'mL', 'ea'], true)
                || ! is_array($pack['unit_size'] ?? null)
                || ! in_array($pack['unit_size']['dimension'] ?? null, ['weight', 'volume', 'count'], true)) {
                continue;
            }
            try {
                $quantity = BigDecimal::of($pack['case_total']);
                if ($quantity->isLessThanOrEqualTo(0)) {
                    continue;
                }
                $safe[] = [
                    'value' => (string) $quantity->toScale(12, RoundingMode::UNNECESSARY),
                    'unit' => $pack['case_total_unit'],
                    'dimension' => $pack['unit_size']['dimension'],
                ];
            } catch (MathException) {
                continue;
            }
        }

        // More than one supplied fixed case total is ambiguous. The original
        // evidence remains on the observation, but no denominator is invented.
        return count($safe) === 1 ? $safe[0] : [];
    }

    private function hasUnsafeWarning(NormalizedImportRecord $normalized): bool
    {
        foreach ($normalized->warnings as $warning) {
            if (in_array($warning['code'], self::UNSAFE_QUANTITY_WARNINGS, true)) {
                return true;
            }
        }

        return false;
    }

    private function isNewer(PriceObservation $candidate, PriceObservation $current): bool
    {
        if ($candidate->observed_at->notEqualTo($current->observed_at)) {
            return $candidate->observed_at->greaterThan($current->observed_at);
        }

        return strcmp($candidate->id, $current->id) > 0;
    }
}
