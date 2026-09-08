<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

final class RequestIdTest extends TestCase
{
    public function test_valid_incoming_request_id_is_reused(): void
    {
        $response = $this
            ->withHeader('X-Request-ID', 'client-request-123')
            ->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'client-request-123')
            ->assertJsonPath('request_id', 'client-request-123');
    }

    public function test_invalid_incoming_request_id_is_replaced(): void
    {
        $response = $this
            ->withHeader('X-Request-ID', 'contains unsafe spaces')
            ->getJson('/api/v1/health');
        $requestId = $response->headers->get('X-Request-ID');

        $response->assertOk()->assertJsonPath('request_id', $requestId);
        $this->assertIsString($requestId);
        $this->assertTrue(Str::isUuid($requestId));
    }

    public function test_api_errors_use_the_standard_envelope(): void
    {
        $response = $this->getJson('/api/v1/missing');
        $requestId = $response->headers->get('X-Request-ID');

        $response
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonPath('error.message', 'Not Found')
            ->assertJsonPath('error.request_id', $requestId);
        $this->assertIsString($requestId);
    }
}
