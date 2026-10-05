<?php

namespace App\Services;

use App\Enums\ComparisonClass;
use App\Enums\MatchClass;
use App\Enums\OfferAvailability;
use App\Enums\PriceFreshness;
use App\Enums\SupplierAccountStatus;
use App\Models\CanonicalProduct;
use App\Models\PriceObservation;
use App\Models\RestaurantLocation;
use App\Models\SupplierOffer;
use App\Models\VendorItemMapping;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/** Server-authoritative decimal comparison for the first offer view. */
final class OfferComparisonService
{
    public const FORMULA_VERSION = SupplierOfferProjectionService::FORMULA_VERSION;

    private const NORMALIZED_PRICE_SCALE = 8;

    /** @var array<string, array{unit: string, factor: string}> */
    private const DISPLAY_UNITS = [
        'weight' => ['unit' => 'lb', 'factor' => '453.59237'],
        'volume' => ['unit' => 'gal', 'factor' => '3785.411784'],
        'count' => ['unit' => 'ea', 'factor' => '1'],
    ];

    public function __construct(private readonly PriceFreshnessService $freshness) {}

    /**
     * @param  array{supplier_ids?: list<string>, availability?: list<string>, freshness?: list<string>, match_classes?: list<string>, sort?: string, page?: int, per_page?: int}  $filters
     * @return array<string, mixed>
     */
    public function compare(
        string $organizationId,
        RestaurantLocation $location,
        CanonicalProduct $product,
        array $filters,
    ): array {
        $offers = $this->offers($organizationId, $location->id, $product->id, $filters);
        $rows = [];
        foreach ($offers as $offer) {
            $row = $this->row($offer);
            if ($this->matchesFilters($row, $filters)) {
                $rows[] = $row;
            }
        }

        $warnings = $this->applyWinners($rows);
        $this->sort($rows, $filters['sort'] ?? 'supplier');
        $perPage = $filters['per_page'] ?? 50;
        $page = $filters['page'] ?? 1;
        $total = count($rows);
        $pageRows = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'canonical_product' => [
                'id' => $product->id,
                'english_name' => $product->english_name,
                'category' => ['id' => $product->category->id, 'english_name' => $product->category->english_name],
                'attributes' => $product->attributes,
            ],
            'location' => ['id' => $location->id, 'name' => $location->name],
            'formula_version' => self::FORMULA_VERSION,
            'freshness_summary' => $this->freshnessSummary($rows),
            'warnings' => $warnings,
            'offers' => array_map(fn (array $row): array => $this->publicRow($row), $pageRows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $perPage)),
            ],
            // Supplier-level failure status is not yet persisted by ingestion;
            // retain a safe, explicit shape instead of treating it as a full
            // comparison failure.
            'partial' => ['supplier_accounts' => []],
        ];
    }

    /**
     * @param  array{supplier_ids?: list<string>, match_classes?: list<string>}  $filters
     * @return Collection<int, SupplierOffer>
     */
    private function offers(string $organizationId, string $locationId, string $productId, array $filters): Collection
    {
        $query = SupplierOffer::query()
            ->where('organization_id', $organizationId)
            ->where('restaurant_location_id', $locationId)
            ->where('is_active', true)
            ->whereNotNull('current_price_observation_id')
            ->whereHas('supplierAccount', fn (Builder $accounts): Builder => $accounts->where('status', SupplierAccountStatus::Active->value))
            ->whereHas('vendorItem.currentMapping', function (Builder $mappings) use ($productId, $filters): Builder {
                $mappings->where('canonical_product_id', $productId);
                if (($filters['match_classes'] ?? []) !== []) {
                    $mappings->whereIn('match_class', $this->mappingClasses($filters['match_classes']));
                }

                return $mappings;
            })
            ->with('supplierAccount.supplierDefinition')
            ->with('currentPriceObservation')
            ->with([
                'vendorItem.currentMapping' => function (Relation $mappings) use ($productId): void {
                    $mappings->where('canonical_product_id', $productId)->with(['productVariant', 'canonicalProduct']);
                },
            ])
            ->orderBy('id');
        if (($filters['supplier_ids'] ?? []) !== []) {
            $query->whereIn('supplier_account_id', $filters['supplier_ids']);
        }

        return $query->get();
    }

    /** @return array<string, mixed> */
    private function row(SupplierOffer $offer): array
    {
        $observation = $offer->currentPriceObservation;
        $mapping = $offer->vendorItem->currentMapping;
        if (! $observation instanceof PriceObservation || ! $mapping instanceof VendorItemMapping) {
            throw new \LogicException('Eligible offer query returned incomplete comparison relations.');
        }
        $class = $this->comparisonClass($mapping->match_class);
        $freshness = $this->freshness->classify($offer->supplierAccount, $observation);
        $normalized = $this->normalized($observation, $class);
        $rowWarnings = [];
        if ($class === ComparisonClass::Alternative) {
            $rowWarnings[] = 'alternative_not_directly_interchangeable';
        }
        if ($class === ComparisonClass::NotSafelyComparable) {
            $rowWarnings[] = 'not_safely_comparable';
        }
        if ($normalized === null) {
            $rowWarnings[] = 'normalized_price_unavailable';
        }

        return [
            'id' => $offer->id,
            'supplier' => [
                'id' => $offer->supplierAccount->id,
                'display_name' => $offer->supplierAccount->display_name,
                'name' => $offer->supplierAccount->supplierDefinition->name,
            ],
            'raw_supplier_name' => $offer->vendorItem->raw_description,
            'pack' => $observation->source_pack_evidence,
            'case_price' => $this->plain(BigDecimal::of($observation->case_price)),
            'currency' => $observation->currency,
            'normalized_price' => $normalized['price'] ?? null,
            'normalized_unit' => $normalized['unit'] ?? null,
            'normalized_denominator' => $normalized['denominator'] ?? null,
            'availability' => $observation->availability->value,
            'source_updated_at' => $observation->source_updated_at?->toIso8601String(),
            'observed_at' => $observation->observed_at->toIso8601String(),
            'imported_at' => $observation->imported_at?->toIso8601String(),
            'freshness' => $freshness->value,
            'comparison_class' => $class->value,
            'warnings' => $rowWarnings,
            'winner_labels' => [],
            'calculation' => [
                'source_case_price' => $this->plain(BigDecimal::of($observation->case_price)),
                'currency' => $observation->currency,
                'source_price_evidence' => $observation->source_price_evidence,
                'source_pack_evidence' => $observation->source_pack_evidence,
                'normalized_denominator' => $normalized['denominator'] ?? null,
                'normalized_price' => $normalized['price'] ?? null,
                'normalized_unit' => $normalized['unit'] ?? null,
                'formula' => $normalized === null ? null : 'case_price * exact_unit_factor / normalized_quantity',
                'formula_version' => self::FORMULA_VERSION,
                'observation' => [
                    'id' => $observation->id,
                    'source_updated_at' => $observation->source_updated_at?->toIso8601String(),
                    'observed_at' => $observation->observed_at->toIso8601String(),
                    'imported_at' => $observation->imported_at?->toIso8601String(),
                    'import_run_id' => $observation->import_run_id,
                    'normalized_import_record_id' => $observation->normalized_import_record_id,
                ],
                'match' => [
                    'mapping_id' => $mapping->id,
                    'canonical_product_id' => $mapping->canonical_product_id,
                    'product_variant_id' => $mapping->product_variant_id,
                    'match_class' => $mapping->match_class->value,
                    'confidence' => $mapping->confidence->value,
                    'matcher_version' => $mapping->matcher_version,
                    'evidence' => $mapping->evidence,
                ],
            ],
            '_case_decimal' => BigDecimal::of($observation->case_price),
            '_normalized_decimal' => $normalized['decimal'] ?? null,
            '_dimension' => $observation->dimension,
            '_winner_eligible' => in_array($class, [ComparisonClass::Exact, ComparisonClass::Equivalent], true),
            '_observed_at' => $observation->observed_at,
        ];
    }

    /**
     * Produces the same server-authoritative values used by comparison without
     * issuing a query. Search uses this only for already tenant-scoped offers.
     *
     * @return array<string, mixed>
     */
    public function describeOffer(SupplierOffer $offer): array
    {
        return $this->row($offer);
    }

    /** @return array{price: string, unit: string, denominator: array<string, string>, decimal: BigDecimal}|null */
    private function normalized(PriceObservation $observation, ComparisonClass $class): ?array
    {
        if ($class === ComparisonClass::NotSafelyComparable
            || $observation->normalized_quantity === null
            || $observation->dimension === null
            || ! isset(self::DISPLAY_UNITS[$observation->dimension])) {
            return null;
        }
        $display = self::DISPLAY_UNITS[$observation->dimension];
        try {
            $quantity = BigDecimal::of($observation->normalized_quantity);
            if ($quantity->isLessThanOrEqualTo(0)) {
                return null;
            }
            $factor = BigDecimal::of($display['factor']);
            $denominator = $quantity->dividedBy($factor, self::NORMALIZED_PRICE_SCALE, RoundingMode::HALF_UP);
            $price = BigDecimal::of($observation->case_price)
                ->multipliedBy($factor)
                ->dividedBy($quantity, self::NORMALIZED_PRICE_SCALE, RoundingMode::HALF_UP);

            return [
                'price' => $this->plain($price),
                'unit' => $display['unit'],
                'denominator' => [
                    'value' => $this->plain($denominator),
                    'unit' => $display['unit'],
                    'canonical_value' => $this->plain($quantity),
                    'canonical_unit' => (string) $observation->normalized_unit,
                ],
                'decimal' => $price,
            ];
        } catch (MathException) {
            return null;
        }
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function applyWinners(array &$rows): array
    {
        $eligible = array_values(array_filter($rows, static fn (array $row): bool => $row['_winner_eligible']));
        $warnings = [];
        $currencies = array_values(array_unique(array_map(static fn (array $row): string => $row['currency'], $eligible)));
        if (count($currencies) > 1) {
            $warnings[] = 'case_price_currency_mismatch';
        }
        if (count($currencies) === 1 && ($winner = $this->winner($eligible, '_case_decimal')) !== null) {
            $this->label($rows, $winner['id'], 'lowest_case_price');
        }

        $normalized = array_values(array_filter($eligible, static fn (array $row): bool => $row['_normalized_decimal'] instanceof BigDecimal));
        $dimensions = array_values(array_unique(array_map(static fn (array $row): ?string => $row['_dimension'], $normalized)));
        if (count($dimensions) > 1) {
            $warnings[] = 'incompatible_normalized_dimensions';
        }
        if (count($currencies) === 1 && count($dimensions) === 1 && ($winner = $this->winner($normalized, '_normalized_decimal')) !== null) {
            $this->label($rows, $winner['id'], 'best_normalized_price');
        }

        $available = array_values(array_filter($normalized, static fn (array $row): bool => $row['availability'] === OfferAvailability::Available->value
            && ! in_array($row['freshness'], [PriceFreshness::Stale->value, PriceFreshness::ConnectionFailed->value], true)));
        if (count($currencies) === 1 && count($dimensions) === 1 && ($winner = $this->winner($available, '_normalized_decimal')) !== null) {
            $this->label($rows, $winner['id'], 'best_available_option');
        }

        return $warnings;
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function winner(array $rows, string $decimalKey): ?array
    {
        if ($rows === []) {
            return null;
        }
        usort($rows, function (array $left, array $right) use ($decimalKey): int {
            /** @var BigDecimal $leftDecimal */
            $leftDecimal = $left[$decimalKey];
            /** @var BigDecimal $rightDecimal */
            $rightDecimal = $right[$decimalKey];
            $price = $leftDecimal->compareTo($rightDecimal);
            if ($price !== 0) {
                return $price;
            }
            /** @var CarbonImmutable $leftTime */
            $leftTime = $left['_observed_at'];
            /** @var CarbonImmutable $rightTime */
            $rightTime = $right['_observed_at'];
            $newest = $rightTime->getTimestamp() <=> $leftTime->getTimestamp();
            if ($newest !== 0) {
                return $newest;
            }

            return strcmp($left['id'], $right['id']);
        });

        return $rows[0];
    }

    /** @param list<array<string, mixed>> $rows */
    private function label(array &$rows, string $id, string $label): void
    {
        foreach ($rows as &$row) {
            if ($row['id'] === $id) {
                $row['winner_labels'][] = $label;
                break;
            }
        }
        unset($row);
    }

    /** @param list<array<string, mixed>> $rows */
    private function sort(array &$rows, string $sort): void
    {
        usort($rows, function (array $left, array $right) use ($sort): int {
            if ($sort === 'lowest_case_price') {
                /** @var BigDecimal $leftPrice */
                $leftPrice = $left['_case_decimal'];
                /** @var BigDecimal $rightPrice */
                $rightPrice = $right['_case_decimal'];
                $comparison = $leftPrice->compareTo($rightPrice);
            } elseif ($sort === 'lowest_normalized_price') {
                $comparison = $this->nullableDecimalCompare($left['_normalized_decimal'], $right['_normalized_decimal']);
            } elseif ($sort === 'newest_observation') {
                /** @var CarbonImmutable $leftTime */
                $leftTime = $left['_observed_at'];
                /** @var CarbonImmutable $rightTime */
                $rightTime = $right['_observed_at'];
                $comparison = $rightTime->getTimestamp() <=> $leftTime->getTimestamp();
            } else {
                $comparison = strcmp($left['supplier']['display_name'], $right['supplier']['display_name']);
            }
            if ($comparison !== 0) {
                return $comparison;
            }

            return strcmp($left['id'], $right['id']);
        });
    }

    private function nullableDecimalCompare(?BigDecimal $left, ?BigDecimal $right): int
    {
        if ($left === null && $right === null) {
            return 0;
        }
        if ($left === null) {
            return 1;
        }
        if ($right === null) {
            return -1;
        }

        return $left->compareTo($right);
    }

    /** @param array<string, mixed> $row
     * @param  array{availability?: list<string>, freshness?: list<string>, match_classes?: list<string>}  $filters
     */
    private function matchesFilters(array $row, array $filters): bool
    {
        return (($filters['availability'] ?? []) === [] || in_array($row['availability'], $filters['availability'], true))
            && (($filters['freshness'] ?? []) === [] || in_array($row['freshness'], $filters['freshness'], true))
            && (($filters['match_classes'] ?? []) === [] || in_array($row['comparison_class'], $filters['match_classes'], true));
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function freshnessSummary(array $rows): array
    {
        $summary = array_fill_keys(array_map(static fn (PriceFreshness $freshness): string => $freshness->value, PriceFreshness::cases()), 0);
        foreach ($rows as $row) {
            $summary[$row['freshness']]++;
        }

        return $summary;
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

    /** @param list<string> $classes
     * @return list<string>
     */
    private function mappingClasses(array $classes): array
    {
        $result = [];
        foreach ($classes as $class) {
            $result = [...$result, ...match ($class) {
                ComparisonClass::Exact->value => [MatchClass::ExactMatch->value],
                ComparisonClass::Equivalent->value => [MatchClass::EquivalentProduct->value],
                ComparisonClass::Alternative->value => [MatchClass::ComparableAlternative->value],
                ComparisonClass::NotSafelyComparable->value => [MatchClass::RelatedProduct->value, MatchClass::NotComparable->value],
                default => [],
            }];
        }

        return array_values(array_unique($result));
    }

    private function plain(BigDecimal $value): string
    {
        $text = (string) $value;
        if (! str_contains($text, '.')) {
            return $text;
        }
        $trimmed = rtrim(rtrim($text, '0'), '.');

        return $trimmed === '-0' ? '0' : $trimmed;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row): array
    {
        unset($row['_case_decimal'], $row['_normalized_decimal'], $row['_dimension'], $row['_winner_eligible'], $row['_observed_at']);

        return $row;
    }
}
