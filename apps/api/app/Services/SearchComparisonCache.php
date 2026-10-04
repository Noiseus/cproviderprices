<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived private response cache. Every key includes the server-authorized
 * organization and location, while a scoped version key supports targeted
 * invalidation after an import or mapping change.
 */
final class SearchComparisonCache
{
    private const TTL_SECONDS = 30;

    /** @param array<string, mixed> $parameters
     * @param  Closure(): array<string, mixed>  $resolver
     * @return array<string, mixed>
     */
    public function rememberComparison(
        string $organizationId,
        string $locationId,
        string $canonicalProductId,
        array $parameters,
        Closure $resolver,
    ): array {
        $version = $this->version($organizationId, $locationId);
        $key = 'search-comparison:comparison:'.$organizationId.':'.$locationId.':'.$canonicalProductId.':'.$version.':'
            .hash('sha256', json_encode($this->sort($parameters), JSON_THROW_ON_ERROR));

        /** @var array<string, mixed> */
        return Cache::remember($key, now()->addSeconds(self::TTL_SECONDS), $resolver);
    }

    /** @param array<string, mixed> $parameters
     * @param  Closure(): array<string, mixed>  $resolver
     * @return array<string, mixed>
     */
    public function rememberSearch(
        string $organizationId,
        string $locationId,
        array $parameters,
        Closure $resolver,
    ): array {
        $version = $this->version($organizationId, $locationId);
        $key = 'search-comparison:search:'.$organizationId.':'.$locationId.':'.$version.':'
            .hash('sha256', json_encode($this->sort($parameters), JSON_THROW_ON_ERROR));

        /** @var array<string, mixed> */
        return Cache::remember($key, now()->addSeconds(self::TTL_SECONDS), $resolver);
    }

    public function invalidate(string $organizationId, string $locationId): void
    {
        $key = $this->versionKey($organizationId, $locationId);
        Cache::add($key, 0);
        Cache::increment($key);
    }

    private function version(string $organizationId, string $locationId): int
    {
        $key = $this->versionKey($organizationId, $locationId);
        Cache::add($key, 1);

        return (int) Cache::get($key, 1);
    }

    private function versionKey(string $organizationId, string $locationId): string
    {
        return 'search-comparison:version:'.$organizationId.':'.$locationId;
    }

    /** @param array<mixed> $value
     * @return array<mixed>
     */
    private function sort(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sort($item);
            }
        }

        return $value;
    }
}
