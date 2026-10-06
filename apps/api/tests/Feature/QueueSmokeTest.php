<?php

namespace Tests\Feature;

use App\Jobs\VerifyQueueProcessing;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Mockery;
use Tests\TestCase;

final class QueueSmokeTest extends TestCase
{
    public function test_queue_smoke_job_writes_a_short_lived_redis_acknowledgement(): void
    {
        /** @var CacheFactory&Mockery\MockInterface $cache */
        $cache = Mockery::mock(CacheFactory::class);
        /** @var Repository&Mockery\MockInterface $store */
        $store = Mockery::mock(Repository::class);
        $cache->shouldReceive('store')->once()->with('redis')->andReturn($store);
        $store->shouldReceive('put')->once()->with(
            'deployment-smoke:queue:synthetic',
            true,
            Mockery::type(DateTimeInterface::class),
        );

        (new VerifyQueueProcessing('deployment-smoke:queue:synthetic'))->handle($cache);
    }
}
