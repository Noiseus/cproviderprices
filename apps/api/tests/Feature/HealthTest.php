<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_health_endpoint_is_available(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'api')
            ->assertJsonStructure(['timestamp']);
    }

    public function test_readiness_checks_required_dependencies(): void
    {
        DB::shouldReceive('select')->once()->with('select 1')->andReturn([['result' => 1]]);
        Redis::shouldReceive('connection->ping')->once()->andReturn('PONG');
        Storage::fake('s3');

        $response = $this->getJson('/api/v1/readiness');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.redis', 'ok')
            ->assertJsonPath('checks.object_storage', 'ok')
            ->assertJsonStructure(['timestamp']);
    }

    public function test_readiness_returns_service_unavailable_when_a_dependency_fails(): void
    {
        DB::shouldReceive('select')->once()->andThrow(new \RuntimeException('Unavailable'));
        Storage::fake('s3');

        $response = $this->getJson('/api/v1/readiness');

        $response
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.database', 'failed');
    }
}
