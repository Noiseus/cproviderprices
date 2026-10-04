<?php

namespace App\Services;

use App\Support\CatalogText;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/** Lightweight deterministic token extraction; this is not natural-language parsing. */
final class SearchQueryParser
{
    /** @var array<string, array{dimension: string, factor: string, unit: string}> */
    private const UNITS = [
        'g' => ['dimension' => 'weight', 'factor' => '1', 'unit' => 'g'],
        'gram' => ['dimension' => 'weight', 'factor' => '1', 'unit' => 'g'],
        'grams' => ['dimension' => 'weight', 'factor' => '1', 'unit' => 'g'],
        'kg' => ['dimension' => 'weight', 'factor' => '1000', 'unit' => 'g'],
        'lb' => ['dimension' => 'weight', 'factor' => '453.59237', 'unit' => 'g'],
        'lbs' => ['dimension' => 'weight', 'factor' => '453.59237', 'unit' => 'g'],
        'pound' => ['dimension' => 'weight', 'factor' => '453.59237', 'unit' => 'g'],
        'pounds' => ['dimension' => 'weight', 'factor' => '453.59237', 'unit' => 'g'],
        'oz' => ['dimension' => 'weight', 'factor' => '28.349523125', 'unit' => 'g'],
        'ounce' => ['dimension' => 'weight', 'factor' => '28.349523125', 'unit' => 'g'],
        'ounces' => ['dimension' => 'weight', 'factor' => '28.349523125', 'unit' => 'g'],
        'ml' => ['dimension' => 'volume', 'factor' => '1', 'unit' => 'mL'],
        'l' => ['dimension' => 'volume', 'factor' => '1000', 'unit' => 'mL'],
        'liter' => ['dimension' => 'volume', 'factor' => '1000', 'unit' => 'mL'],
        'liters' => ['dimension' => 'volume', 'factor' => '1000', 'unit' => 'mL'],
        'litre' => ['dimension' => 'volume', 'factor' => '1000', 'unit' => 'mL'],
        'litres' => ['dimension' => 'volume', 'factor' => '1000', 'unit' => 'mL'],
        'gal' => ['dimension' => 'volume', 'factor' => '3785.411784', 'unit' => 'mL'],
        'gallon' => ['dimension' => 'volume', 'factor' => '3785.411784', 'unit' => 'mL'],
        'gallons' => ['dimension' => 'volume', 'factor' => '3785.411784', 'unit' => 'mL'],
        'ea' => ['dimension' => 'count', 'factor' => '1', 'unit' => 'ea'],
        'each' => ['dimension' => 'count', 'factor' => '1', 'unit' => 'ea'],
        'ct' => ['dimension' => 'count', 'factor' => '1', 'unit' => 'ea'],
        'count' => ['dimension' => 'count', 'factor' => '1', 'unit' => 'ea'],
    ];

    /**
     * @return array{normalized: string, terms: list<string>, size_code: string|null, measurement: array{dimension: string, canonical_value: string, canonical_unit: string}|null}
     */
    public function parse(string $query): array
    {
        $normalized = CatalogText::normalize($query);
        $sizeCode = preg_match('/\b(\d{1,3}x\d{1,3})\b/i', $normalized, $size) === 1 ? strtoupper($size[1]) : null;
        $measurement = $this->measurement($normalized);
        $ignored = [];
        if ($sizeCode !== null) {
            $ignored[] = strtolower($sizeCode);
        }
        if ($measurement !== null) {
            foreach (preg_split('/\s+/', $normalized) ?: [] as $token) {
                if ($token === $measurement['source_value'] || $token === $measurement['source_unit']) {
                    $ignored[] = $token;
                }
            }
        }
        $terms = array_values(array_filter(CatalogText::tokens($normalized), static fn (string $token): bool => ! in_array($token, $ignored, true)));

        return [
            'normalized' => $normalized,
            'terms' => $terms,
            'size_code' => $sizeCode,
            'measurement' => $measurement === null ? null : [
                'dimension' => $measurement['dimension'],
                'canonical_value' => $measurement['canonical_value'],
                'canonical_unit' => $measurement['canonical_unit'],
            ],
        ];
    }

    /** @return array{source_value: string, source_unit: string, dimension: string, canonical_value: string, canonical_unit: string}|null */
    private function measurement(string $query): ?array
    {
        if (preg_match('/\b(\d{1,12}(?:\.\d{1,9})?)\s*(g|grams?|kg|lbs?|pounds?|oz|ounces?|ml|l|lit(?:er|re)s?|gal(?:lons?)?|ea|each|ct|count)\b/i', $query, $match) !== 1) {
            return null;
        }
        $unit = strtolower($match[2]);
        $definition = self::UNITS[$unit] ?? null;
        if ($definition === null) {
            return null;
        }
        try {
            $canonical = BigDecimal::of($match[1])
                ->multipliedBy($definition['factor'])
                ->toScale(12, RoundingMode::UNNECESSARY);
            if ($canonical->isLessThanOrEqualTo(0)) {
                return null;
            }
        } catch (MathException) {
            return null;
        }

        return [
            'source_value' => $match[1],
            'source_unit' => $unit,
            'dimension' => $definition['dimension'],
            'canonical_value' => $this->plain($canonical),
            'canonical_unit' => $definition['unit'],
        ];
    }

    private function plain(BigDecimal $value): string
    {
        $text = (string) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
