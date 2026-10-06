<?php

namespace App\Jobs;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Writes a short-lived acknowledgement for the non-destructive deployment smoke check. */
final class VerifyQueueProcessing implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public readonly string $acknowledgementKey) {}

    public function handle(CacheFactory $cache): void
    {
        $cache->store('redis')->put($this->acknowledgementKey, true, now()->addMinute());
    }
}
