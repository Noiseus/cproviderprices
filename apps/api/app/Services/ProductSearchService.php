<?php

namespace App\Services;

use App\Enums\PriceFreshness;
use App\Enums\SupplierAccountStatus;
use App\Models\CanonicalProduct;
use App\Models\SupplierOffer;
use App\Support\CatalogText;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;

/** Read-only bilingual catalog discovery over global catalog and scoped offers. */
final class ProductSearchService
{
    private const MAX_CANDIDATES = 200;

    public function __construct(
        private readonly SearchQueryParser $queries,
        private readonly OfferComparisonService $comparisons,
    ) {}

    /**
     * @param  array{query: string, category_id?: string|null, supplier_ids?: list<string>, brands?: list<string>, units?: list<string>, availability?: list<string>, freshness?: list<string>, match_classes?: list<string>, sort?: string, page?: int, per_page?: int}  $filters
     * @return array<string, mixed>
     */
    public function search(string $organizationId, string $locationId, array $filters): array
    {
        $startedAt = hrtime(true);
        $parsed = $this->queries->parse($filters['query']);
        $offerRows = $this->eligibleOfferRows($organizationId, $locationId);
        $privateIds = $this->privateCandidateIds($offerRows, $parsed);
        $products = $this->products($parsed, $privateIds, $filters['category_id'] ?? null);
        $grouped = [];
        foreach ($offerRows as $row) {
            $canonicalId = $row['calculation']['match']['evidence']['candidate']['canonical_product_id']
                ?? $row['calculation']['match']['canonical_product_id']
                ?? null;
            // Current mapping is the authoritative association. Its ID is
            // loaded by the offer relation even when older evidence omits it.
            $canonicalId ??= $row['_canonical_product_id'];
            if (is_string($canonicalId)) {
                $grouped[$canonicalId][] = $row;
            }
        }

        $results = [];
        foreach ($products as $product) {
            $rows = $this->filterOfferRows($grouped[$product->id] ?? [], $filters);
            if ($this->requiresOffer($filters) && $rows === []) {
                continue;
            }
            $score = $this->score($product, $rows, $parsed);
            if ($score === null) {
                continue;
            }
            $results[] = $this->result($product, $rows, $score, $parsed);
        }

        $this->sort($results, $filters['sort'] ?? 'relevance');
        $perPage = $filters['per_page'] ?? 25;
        $page = $filters['page'] ?? 1;
        $total = count($results);
        $payload = [
            'results' => array_slice($results, ($page - 1) * $perPage, $perPage),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $perPage)),
            ],
            'partial' => ['supplier_accounts' => []],
        ];
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        Log::info('product.search.completed', [
            'organization_id' => $organizationId,
            'location_id' => $locationId,
            'latency_ms' => $durationMs,
            'zero_results' => $total === 0,
            'result_count_bucket' => $this->bucket($total),
        ]);

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    private function eligibleOfferRows(string $organizationId, string $locationId): array
    {
        $offers = SupplierOffer::query()
            ->where('organization_id', $organizationId)
            ->where('restaurant_location_id', $locationId)
            ->where('is_active', true)
            ->whereNotNull('current_price_observation_id')
            ->whereHas('supplierAccount', fn (Builder $accounts): Builder => $accounts->where('status', SupplierAccountStatus::Active->value))
            ->whereHas('vendorItem.currentMapping')
            ->with('supplierAccount.supplierDefinition')
            ->with('currentPriceObservation')
            ->with([
                'vendorItem.currentMapping' => function (Relation $mapping): void {
                    $mapping->with(['canonicalProduct', 'productVariant']);
                },
            ])
            ->orderBy('id')
            ->get();
        $rows = [];
        foreach ($offers as $offer) {
            $row = $this->comparisons->describeOffer($offer);
            $mapping = $offer->vendorItem->currentMapping;
            if ($mapping === null) {
                continue;
            }
            $row['_canonical_product_id'] = $mapping->canonical_product_id;
            $row['_vendor_description'] = $offer->vendorItem->normalized_description;
            $row['_brand'] = $offer->vendorItem->brand;
            $row['_size_code'] = $offer->vendorItem->normalized_attributes['size_code'] ?? null;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{terms: list<string>}  $parsed
     * @return list<string>
     */
    private function privateCandidateIds(array $rows, array $parsed): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $privateText = CatalogText::normalize((string) $row['_vendor_description'].' '.(string) ($row['_brand'] ?? ''));
            if ($parsed['terms'] !== [] && $this->containsTerms($privateText, $parsed['terms'])) {
                $ids[] = $row['_canonical_product_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array{normalized: string, terms: list<string>}  $parsed
     * @param  list<string>  $privateIds
     * @return Collection<int, CanonicalProduct>
     */
    private function products(array $parsed, array $privateIds, ?string $categoryId): Collection
    {
        $global = CanonicalProduct::query()->where('is_active', true);
        if ($categoryId !== null) {
            $global->where('category_id', $categoryId);
        }
        $global->where(function (Builder $matches) use ($parsed): void {
            $matches->where('normalized_key', $parsed['normalized'])
                ->orWhereHas('aliases', fn (Builder $aliases): Builder => $aliases->where('normalized_alias', $parsed['normalized']))
                ->orWhereHas('variants', function (Builder $variants) use ($parsed): void {
                    $variants->where('normalized_key', $parsed['normalized']);
                });
            if (count($parsed['terms']) === 1) {
                $term = $parsed['terms'][0];
                $matches->orWhereRaw('normalized_key % ?', [$term])
                    ->orWhereHas('aliases', fn (Builder $aliases): Builder => $aliases->whereRaw('normalized_alias % ?', [$term]));
            }
            if ($parsed['terms'] !== []) {
                $matches->orWhere(function (Builder $canonical) use ($parsed): void {
                    foreach (array_slice($parsed['terms'], 0, 5) as $term) {
                        $canonical->where('normalized_key', 'like', '%'.$term.'%');
                    }
                })->orWhereHas('aliases', function (Builder $aliases) use ($parsed): void {
                    foreach (array_slice($parsed['terms'], 0, 5) as $term) {
                        $aliases->where('normalized_alias', 'like', '%'.$term.'%');
                    }
                })->orWhereHas('variants', function (Builder $variants) use ($parsed): void {
                    foreach (array_slice($parsed['terms'], 0, 5) as $term) {
                        $variants->where('normalized_key', 'like', '%'.$term.'%');
                    }
                });
            }
        });
        $globalIds = $global->orderBy('id')->limit(self::MAX_CANDIDATES)->pluck('id')->all();
        $ids = array_values(array_unique([...$globalIds, ...$privateIds]));
        if ($ids === []) {
            return (new CanonicalProduct)->newCollection();
        }

        return CanonicalProduct::query()
            ->with(['category', 'aliases', 'variants'])
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{supplier_ids?: list<string>, brands?: list<string>, units?: list<string>, availability?: list<string>, freshness?: list<string>, match_classes?: list<string>}  $filters
     * @return list<array<string, mixed>>
     */
    private function filterOfferRows(array $rows, array $filters): array
    {
        return array_values(array_filter($rows, function (array $row) use ($filters): bool {
            if (($filters['supplier_ids'] ?? []) !== [] && ! in_array($row['supplier']['id'], $filters['supplier_ids'], true)) {
                return false;
            }
            if (($filters['brands'] ?? []) !== []) {
                $brand = is_string($row['_brand']) ? CatalogText::normalize($row['_brand']) : '';
                if (! in_array($brand, $filters['brands'], true)) {
                    return false;
                }
            }
            if (($filters['units'] ?? []) !== [] && ! in_array($row['_dimension'] ?? 'case', $filters['units'], true) && ! in_array('case', $filters['units'], true)) {
                return false;
            }
            if (($filters['availability'] ?? []) !== [] && ! in_array($row['availability'], $filters['availability'], true)) {
                return false;
            }
            if (($filters['freshness'] ?? []) !== [] && ! in_array($row['freshness'], $filters['freshness'], true)) {
                return false;
            }
            if (($filters['match_classes'] ?? []) !== [] && ! in_array($row['comparison_class'], $filters['match_classes'], true)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{normalized: string, terms: list<string>, size_code: string|null, measurement: array{dimension: string, canonical_value: string, canonical_unit: string}|null}  $parsed
     */
    private function score(CanonicalProduct $product, array $rows, array $parsed): ?int
    {
        $score = 0;
        $matched = false;
        if ($product->normalized_key === $parsed['normalized']) {
            $score = 100;
            $matched = true;
        }
        foreach ($product->aliases as $alias) {
            if ($alias->normalized_alias === $parsed['normalized']) {
                $score = max($score, $alias->locale === 'es' ? 98 : 95);
                $matched = true;
            } elseif ($this->containsTerms($alias->normalized_alias, $parsed['terms'])) {
                $score = max($score, 75);
                $matched = true;
            }
        }
        if ($this->containsTerms($product->normalized_key, $parsed['terms'])) {
            $score = max($score, 80 + min(15, count($parsed['terms']) * 5));
            $matched = true;
        }
        foreach ($product->variants as $variant) {
            if ($variant->normalized_key === $parsed['normalized'] || $this->containsTerms($variant->normalized_key, $parsed['terms'])) {
                $score = max($score, 85);
                $matched = true;
            }
        }
        if (! $matched && $rows !== []) {
            $score = 40;
            $matched = true;
        }
        if (! $matched) {
            // PostgreSQL trigram prefilter may legitimately retrieve a typo
            // with no exact token overlap; retain it at a low stable score.
            $score = 10;
        }

        foreach ($rows as $row) {
            if ($this->containsTerms((string) $row['_vendor_description'], $parsed['terms'])) {
                $score += 8;
            }
            if (is_string($row['_brand'] ?? null) && $this->containsTerms(CatalogText::normalize($row['_brand']), $parsed['terms'])) {
                $score += 5;
            }
            if ($parsed['size_code'] !== null) {
                if (($row['_size_code'] ?? null) === $parsed['size_code']) {
                    $score += 25;
                } elseif (isset($product->attributes['size_code']) && $product->attributes['size_code'] !== $parsed['size_code']) {
                    return null;
                }
            }
            if ($parsed['measurement'] !== null) {
                $denominator = $row['normalized_denominator'];
                if (is_array($denominator) && $denominator['canonical_unit'] === $parsed['measurement']['canonical_unit']) {
                    if ($this->sameDecimal((string) $denominator['canonical_value'], $parsed['measurement']['canonical_value'])) {
                        $score += 20;
                    } else {
                        $score -= 5;
                    }
                } elseif ($row['_dimension'] !== null && $row['_dimension'] !== $parsed['measurement']['dimension']) {
                    $score -= 25;
                }
            }
        }

        return $score;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{normalized: string, terms: list<string>}  $parsed
     * @return array<string, mixed>
     */
    private function result(CanonicalProduct $product, array $rows, int $score, array $parsed): array
    {
        $suppliers = [];
        $classes = ['exact' => 0, 'equivalent' => 0, 'alternative' => 0, 'not_safely_comparable' => 0];
        $freshness = array_fill_keys(
            array_map(static fn (PriceFreshness $state): string => $state->value, PriceFreshness::cases()),
            0,
        );
        foreach ($rows as $row) {
            $suppliers[$row['supplier']['id']] = ['id' => $row['supplier']['id'], 'display_name' => $row['supplier']['display_name']];
            $classes[$row['comparison_class']]++;
            $freshness[$row['freshness']]++;
        }

        return [
            'canonical_product' => [
                'id' => $product->id,
                'english_name' => $product->english_name,
                'category' => ['id' => $product->category->id, 'english_name' => $product->category->english_name],
                'attributes' => $product->attributes,
            ],
            'variants' => $product->variants
                ->filter(fn ($variant): bool => $variant->normalized_key === $parsed['normalized'] || $this->containsTerms($variant->normalized_key, $parsed['terms']))
                ->map(fn ($variant): array => ['id' => $variant->id, 'code' => $variant->code, 'attributes' => $variant->attributes])
                ->values()
                ->all(),
            'offer_count' => count($rows),
            'suppliers' => array_slice(array_values($suppliers), 0, 3),
            'supplier_count' => count($suppliers),
            'match_quality_summary' => $classes,
            'freshness_summary' => $freshness,
            'price_summary' => $this->priceSummary($rows),
            'relevance_score' => $score,
            '_case_sort' => $this->minimum($rows, '_case_decimal'),
            '_normalized_sort' => $this->minimum($rows, '_normalized_decimal'),
            '_newest_sort' => $this->newest($rows),
        ];
    }

    /** @param list<array<string, mixed>> $rows
     * @return array{lowest_case_price: string|null, currency: string|null, lowest_normalized_price: string|null, normalized_unit: string|null}
     */
    private function priceSummary(array $rows): array
    {
        $eligible = array_values(array_filter($rows, static fn (array $row): bool => $row['_winner_eligible']));
        $currencies = array_values(array_unique(array_map(static fn (array $row): string => $row['currency'], $eligible)));
        $case = count($currencies) === 1 ? $this->minimum($eligible, '_case_decimal') : null;
        $normalizedRows = array_values(array_filter($eligible, static fn (array $row): bool => $row['_normalized_decimal'] instanceof BigDecimal));
        $dimensions = array_values(array_unique(array_map(static fn (array $row): ?string => $row['_dimension'], $normalizedRows)));
        $normalized = count($currencies) === 1 && count($dimensions) === 1 ? $this->minimum($normalizedRows, '_normalized_decimal') : null;
        $unit = $normalized === null || $normalizedRows === [] ? null : $normalizedRows[0]['normalized_unit'];

        return [
            'lowest_case_price' => $case === null ? null : $this->plain($case),
            'currency' => count($currencies) === 1 ? $currencies[0] : null,
            'lowest_normalized_price' => $normalized === null ? null : $this->plain($normalized),
            'normalized_unit' => $unit,
        ];
    }

    /**
     * @param  array{query: string, category_id?: string|null, supplier_ids?: list<string>, brands?: list<string>, units?: list<string>, availability?: list<string>, freshness?: list<string>, match_classes?: list<string>, sort?: string, page?: int, per_page?: int}  $filters
     */
    private function requiresOffer(array $filters): bool
    {
        return ($filters['supplier_ids'] ?? []) !== []
            || ($filters['brands'] ?? []) !== []
            || ($filters['units'] ?? []) !== []
            || ($filters['availability'] ?? []) !== []
            || ($filters['freshness'] ?? []) !== []
            || ($filters['match_classes'] ?? []) !== [];
    }

    /** @param list<array<string, mixed>> $results */
    private function sort(array &$results, string $sort): void
    {
        usort($results, function (array $left, array $right) use ($sort): int {
            if ($sort === 'lowest_case_price') {
                $comparison = $this->nullableDecimalCompare($left['_case_sort'], $right['_case_sort']);
            } elseif ($sort === 'lowest_normalized_price') {
                $comparison = $this->nullableDecimalCompare($left['_normalized_sort'], $right['_normalized_sort']);
            } elseif ($sort === 'newest_observation') {
                $leftTime = $left['_newest_sort'];
                $rightTime = $right['_newest_sort'];
                $comparison = $leftTime === null && $rightTime === null ? 0 : ($leftTime === null ? 1 : ($rightTime === null ? -1 : $rightTime->getTimestamp() <=> $leftTime->getTimestamp()));
            } else {
                $comparison = $right['relevance_score'] <=> $left['relevance_score'];
            }
            if ($comparison !== 0) {
                return $comparison;
            }

            return [$left['canonical_product']['english_name'], $left['canonical_product']['id']] <=> [$right['canonical_product']['english_name'], $right['canonical_product']['id']];
        });
        foreach ($results as &$result) {
            unset($result['_case_sort'], $result['_normalized_sort'], $result['_newest_sort']);
        }
        unset($result);
    }

    /** @param list<array<string, mixed>> $rows */
    private function minimum(array $rows, string $key): ?BigDecimal
    {
        $result = null;
        foreach ($rows as $row) {
            $value = $row[$key] ?? null;
            if (! $value instanceof BigDecimal || ($result !== null && $value->isGreaterThanOrEqualTo($result))) {
                continue;
            }
            $result = $value;
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $rows */
    private function newest(array $rows): ?CarbonImmutable
    {
        $result = null;
        foreach ($rows as $row) {
            $value = $row['_observed_at'] ?? null;
            if (! $value instanceof CarbonImmutable || ($result !== null && $value->lessThanOrEqualTo($result))) {
                continue;
            }
            $result = $value;
        }

        return $result;
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

    /** @param list<string> $terms */
    private function containsTerms(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (! str_contains($text, $term)) {
                return false;
            }
        }

        return $terms !== [];
    }

    private function sameDecimal(string $left, string $right): bool
    {
        try {
            return BigDecimal::of($left)->compareTo(BigDecimal::of($right)) === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function plain(BigDecimal $value): string
    {
        $text = (string) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    private function bucket(int $count): string
    {
        return match (true) {
            $count === 0 => '0',
            $count <= 10 => '1-10',
            $count <= 50 => '11-50',
            default => '51+',
        };
    }
}
