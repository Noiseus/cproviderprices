<?php

namespace App\Services;

use App\Enums\ComparisonClass;
use App\Enums\MatchClass;
use App\Models\CanonicalProduct;
use App\Models\PriceObservation;
use App\Models\RestaurantLocation;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Tenant-scoped, bounded history read model with exact-decimal daily aggregates. */
final class PriceHistoryService
{
    private const NORMALIZED_PRICE_SCALE = 8;

    /** @var array<string, array{unit: string, factor: string}> */
    private const DISPLAY_UNITS = [
        'weight' => ['unit' => 'lb', 'factor' => '453.59237'],
        'volume' => ['unit' => 'gal', 'factor' => '3785.411784'],
        'count' => ['unit' => 'ea', 'factor' => '1'],
    ];

    public function __construct(private readonly PriceFreshnessService $freshness) {}

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, supplier_ids?: list<string>, dimension?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function history(
        string $organizationId,
        RestaurantLocation $location,
        CanonicalProduct $product,
        array $filters,
    ): array {
        $observations = PriceObservation::query()
            ->where('organization_id', $organizationId)
            ->whereBetween('observed_at', [$filters['from'], $filters['to']])
            ->whereHas('supplierOffer', function (Builder $offers) use ($location, $filters): void {
                $offers->where('restaurant_location_id', $location->id);
                if (($filters['supplier_ids'] ?? []) !== []) {
                    $offers->whereIn('supplier_account_id', $filters['supplier_ids']);
                }
            })
            // New observations carry the immutable match snapshot. Legacy rows
            // retain their previous current-mapping fallback without assigning a
            // later mapping to an earlier observation.
            ->where(function (Builder $history) use ($product): void {
                $history->where('match_evidence->canonical_product_id', $product->id)
                    ->orWhere(function (Builder $legacy) use ($product): void {
                        $legacy->whereNull('match_evidence')
                            ->whereHas('supplierOffer.vendorItem.currentMapping', fn (Builder $mappings): Builder => $mappings->where('canonical_product_id', $product->id));
                    });
            })
            ->with([
                'supplierOffer.supplierAccount.supplierDefinition',
                'supplierOffer.vendorItem.currentMapping',
            ])
            ->orderBy('observed_at')
            ->orderBy('id')
            ->limit(2000)
            ->get();

        $rows = [];
        $series = [];
        $unsafe = 0;
        $dimensions = [];
        foreach ($observations as $observation) {
            $row = $this->row($observation);
            if (($filters['dimension'] ?? null) !== null && $row['dimension'] !== $filters['dimension']) {
                continue;
            }
            $rows[] = $row;
            if ($row['aggregation_state'] !== 'safe' || $row['normalized_price'] === null || $row['dimension'] === null) {
                $unsafe++;

                continue;
            }
            $dimensions[$row['dimension']] = true;
            $key = implode('|', [$row['observed_at']->toDateString(), $row['supplier']['id'], $row['currency'], $row['dimension']]);
            if (! isset($series[$key])) {
                $series[$key] = [
                    'date' => $row['observed_at']->toDateString(),
                    'supplier' => $row['supplier'],
                    'currency' => $row['currency'],
                    'dimension' => $row['dimension'],
                    'normalized_unit' => $row['normalized_unit'],
                    'sum' => BigDecimal::zero(),
                    'count' => 0,
                ];
            }
            $series[$key]['sum'] = $series[$key]['sum']->plus(BigDecimal::of($row['normalized_price']));
            $series[$key]['count']++;
        }

        $aggregates = array_values(array_map(function (array $point): array {
            /** @var BigDecimal $sum */
            $sum = $point['sum'];

            return [
                'date' => $point['date'],
                'supplier' => $point['supplier'],
                'currency' => $point['currency'],
                'dimension' => $point['dimension'],
                'normalized_unit' => $point['normalized_unit'],
                'normalized_price' => $this->plain($sum->dividedBy($point['count'], self::NORMALIZED_PRICE_SCALE, RoundingMode::HALF_UP)),
                'observation_count' => $point['count'],
            ];
        }, $series));

        return [
            'canonical_product' => [
                'id' => $product->id,
                'english_name' => $product->english_name,
                'category' => ['id' => $product->category->id, 'english_name' => $product->category->english_name],
            ],
            'location' => ['id' => $location->id, 'name' => $location->name],
            'range' => ['from' => $filters['from']->toIso8601String(), 'to' => $filters['to']->toIso8601String()],
            'aggregation' => [
                'method' => 'daily_mean_per_supplier_compatible_unit',
                'state' => count($dimensions) > 1 ? 'isolated_incompatible_units' : 'compatible_units',
                'dimensions' => array_keys($dimensions),
                'excluded_unsafe_count' => $unsafe,
            ],
            'series' => $aggregates,
            'observations' => array_map(fn (array $row): array => $this->publicRow($row), $rows),
        ];
    }

    /** @return array<string, mixed> */
    private function row(PriceObservation $observation): array
    {
        $offer = $observation->supplierOffer;
        $mapping = $offer->vendorItem->currentMapping;
        $comparisonClass = $mapping === null ? ComparisonClass::NotSafelyComparable : $this->comparisonClass($mapping->match_class);
        $normalized = $comparisonClass === ComparisonClass::NotSafelyComparable ? null : $this->normalized($observation);
        $state = $normalized === null ? $this->unsafeState($observation, $comparisonClass) : 'safe';

        return [
            'id' => $observation->id,
            'supplier' => [
                'id' => $offer->supplierAccount->id,
                'display_name' => $offer->supplierAccount->display_name,
                'name' => $offer->supplierAccount->supplierDefinition->name,
            ],
            'case_price' => $this->plain(BigDecimal::of($observation->case_price)),
            'currency' => $observation->currency,
            'normalized_price' => $normalized['price'] ?? null,
            'normalized_unit' => $normalized['unit'] ?? null,
            'dimension' => $observation->dimension,
            'availability' => $observation->availability->value,
            'freshness' => $this->freshness->classify($offer->supplierAccount, $observation)->value,
            'source_updated_at' => $observation->source_updated_at?->toIso8601String(),
            'observed_at' => $observation->observed_at,
            'imported_at' => $observation->imported_at?->toIso8601String(),
            'aggregation_state' => $state,
            'provenance' => [
                'import_run_id' => $observation->import_run_id,
                'normalized_import_record_id' => $observation->normalized_import_record_id,
                'formula_version' => $observation->formula_version,
            ],
            'match' => $observation->match_evidence ?? ($mapping === null ? null : [
                'mapping_id' => $mapping->id,
                'canonical_product_id' => $mapping->canonical_product_id,
                'product_variant_id' => $mapping->product_variant_id,
                'match_class' => $mapping->match_class->value,
                'confidence' => $mapping->confidence->value,
                'matcher_version' => $mapping->matcher_version,
            ]),
        ];
    }

    /** @return array{price: string, unit: string}|null */
    private function normalized(PriceObservation $observation): ?array
    {
        if ($observation->normalized_quantity === null || $observation->dimension === null || ! isset(self::DISPLAY_UNITS[$observation->dimension])) {
            return null;
        }
        try {
            $quantity = BigDecimal::of($observation->normalized_quantity);
            if ($quantity->isLessThanOrEqualTo(0)) {
                return null;
            }
            $display = self::DISPLAY_UNITS[$observation->dimension];
            $price = BigDecimal::of($observation->case_price)
                ->multipliedBy($display['factor'])
                ->dividedBy($quantity, self::NORMALIZED_PRICE_SCALE, RoundingMode::HALF_UP);

            return ['price' => $this->plain($price), 'unit' => $display['unit']];
        } catch (MathException) {
            return null;
        }
    }

    private function unsafeState(PriceObservation $observation, ComparisonClass $comparisonClass): string
    {
        if (($observation->source_pack_evidence['variable_weight'] ?? false) === true) {
            return 'unsafe_variable_weight';
        }

        return $comparisonClass === ComparisonClass::NotSafelyComparable
            ? 'not_safely_comparable'
            : 'incompatible_or_unsafe_unit';
    }

    private function comparisonClass(MatchClass $matchClass): ComparisonClass
    {
        return match ($matchClass) {
            MatchClass::ExactMatch => ComparisonClass::Exact,
            MatchClass::EquivalentProduct => ComparisonClass::Equivalent,
            MatchClass::ComparableAlternative => ComparisonClass::Alternative,
            MatchClass::RelatedProduct, MatchClass::NotComparable => ComparisonClass::NotSafelyComparable,
        };
    }

    private function plain(BigDecimal $value): string
    {
        $text = (string) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row): array
    {
        $row['observed_at'] = $row['observed_at']->toIso8601String();

        return $row;
    }
}
