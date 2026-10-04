<?php

namespace App\Support;

final class CatalogText
{
    /**
     * Deterministic search/match key; this is deliberately not a fuzzy matcher.
     */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $value = preg_replace('/\b(tom)\b/u', 'tomato', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /** @return list<string> */
    public static function tokens(string $value): array
    {
        $normalized = self::normalize($value);
        if ($normalized === '') {
            return [];
        }

        $tokens = array_values(array_unique(explode(' ', $normalized)));
        sort($tokens, SORT_STRING);

        return $tokens;
    }

    /** @param array<array-key, mixed> $value */
    public static function fingerprint(array $value): string
    {
        return hash('sha256', json_encode(self::sortRecursively($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortRecursively(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(fn (mixed $entry): mixed => is_array($entry) ? self::sortRecursively($entry) : $entry, $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = self::sortRecursively($entry);
            }
        }

        return $value;
    }
}
