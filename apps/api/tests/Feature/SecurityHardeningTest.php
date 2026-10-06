<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use App\Support\ApiErrorResponse;
use App\Support\Audit;
use App\Support\SensitiveData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

final class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_receive_safe_security_headers(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'")
            ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_hsts_requires_explicit_configuration_and_https(): void
    {
        config(['security.hsts_enabled' => true, 'security.hsts_max_age' => 86400]);
        $request = Request::create('/api/v1/health', 'GET', server: ['HTTPS' => 'on']);
        $response = SecurityHeaders::apply($request, response()->json(['status' => 'ok']));

        $this->assertSame('max-age=86400; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
    }

    public function test_cors_credentials_have_explicit_origins_and_methods(): void
    {
        $this->assertTrue(config('cors.supports_credentials'));
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertSame(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], config('cors.allowed_methods'));
        $this->assertNotContains('*', config('cors.allowed_methods'));
    }

    public function test_preflight_allows_only_the_configured_browser_origin_and_methods(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $response
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        $this->assertStringContainsString('POST', (string) $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertStringNotContainsString('*', (string) $response->headers->get('Access-Control-Allow-Methods'));
    }

    public function test_request_middleware_adds_safe_correlation_context_without_request_data(): void
    {
        $log = Log::spy();

        $this->withHeader('X-Request-ID', 'security-trace-123')->getJson('/api/v1/health')->assertOk();

        $log->shouldHaveReceived('withContext')->with([
            'correlation_id' => 'security-trace-123',
            'http_method' => 'GET',
            'route' => null,
        ])->once();
    }

    public function test_csrf_mismatches_have_a_stable_safe_api_error(): void
    {
        $response = ApiErrorResponse::render(
            new TokenMismatchException('token=should-not-leak'),
            Request::create('/api/v1/auth/session', 'POST'),
        );

        $this->assertNotNull($response);
        $this->assertSame(419, $response->getStatusCode());
        $this->assertSame('csrf_token_mismatch', $response->getData(true)['error']['code']);
        $this->assertStringNotContainsString('should-not-leak', $response->getContent());
    }

    public function test_rate_limits_return_a_stable_error_and_retry_header(): void
    {
        $email = 'limit-'.uniqid().'@example.test';
        $key = hash('sha256', $email).'|127.0.0.91';
        RateLimiter::clear($key);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.91'])
                ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'not-the-password'])
                ->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.91'])
            ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'not-the-password'])
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'too_many_requests')
            ->assertHeader('Retry-After');
    }

    public function test_sensitive_context_is_redacted_without_hiding_safe_operational_ids(): void
    {
        $context = SensitiveData::redact([
            'correlation_id' => 'trace-123',
            'organization_id' => 'organization-123',
            'password' => 'private-password',
            'authorization' => 'Bearer private-token',
            'nested' => ['signature' => 'signed-url-secret', 'import_id' => 'import-123'],
        ]);

        $this->assertSame('trace-123', $context['correlation_id']);
        $this->assertSame('organization-123', $context['organization_id']);
        $this->assertSame('[REDACTED]', $context['password']);
        $this->assertSame('[REDACTED]', $context['authorization']);
        $this->assertSame('[REDACTED]', $context['nested']['signature']);
        $this->assertSame('import-123', $context['nested']['import_id']);
    }

    public function test_audit_metadata_cannot_persist_credentials_or_tokens(): void
    {
        $actor = User::factory()->create();
        $audit = Audit::record('security.redaction_test', $actor, metadata: [
            'import_id' => 'import-safe-id',
            'password_confirmation' => 'private-password',
            'token' => 'private-token',
        ]);

        $this->assertSame('import-safe-id', $audit->metadata['import_id']);
        $this->assertSame('[REDACTED]', $audit->metadata['password_confirmation']);
        $this->assertSame('[REDACTED]', $audit->metadata['token']);
        $this->assertStringNotContainsString('private-password', (string) json_encode($audit->metadata));
        $this->assertStringNotContainsString('private-token', (string) json_encode($audit->metadata));
    }

    public function test_unhandled_api_errors_do_not_expose_exception_messages_or_paths(): void
    {
        $response = ApiErrorResponse::render(
            new RuntimeException('SQL failed at C:\\private\\storage with secret=do-not-expose'),
            Request::create('/api/v1/private', 'GET'),
        );

        $this->assertNotNull($response);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('internal_error', $response->getData(true)['error']['code']);
        $this->assertStringNotContainsString('private', $response->getContent());
        $this->assertStringNotContainsString('do-not-expose', $response->getContent());
    }
}
