<?php

namespace Tests\Feature;

use App\Models\MobileAccessToken;
use App\Models\Organization;
use App\Models\RestaurantLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenantContext;
use Tests\TestCase;

final class MobileAuthenticationTest extends TestCase
{
    use BuildsTenantContext;
    use RefreshDatabase;

    public function test_mobile_login_issues_a_hashed_expiring_token_without_a_browser_session(): void
    {
        $password = Str::random(24);
        $user = User::factory()->create(['password' => Hash::make($password)]);
        $organization = Organization::factory()->create();
        $this->membership($user, $organization);

        $response = $this->postJson('/api/v1/mobile/auth/login', [
            'email' => $user->email,
            'password' => $password,
            'device_name' => 'Test iPhone',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('session.user.id', $user->id)
            ->assertJsonPath('session.organizations.0.id', $organization->id)
            ->assertCookieMissing((string) config('session.cookie'));
        $plainTextToken = (string) $response->json('access_token');
        $this->assertStringStartsWith('cpm1.', $plainTextToken);
        $this->assertDatabaseHas('mobile_access_tokens', [
            'user_id' => $user->id,
            'device_name' => 'Test iPhone',
            'token_hash' => hash('sha256', $plainTextToken),
        ]);
        $this->assertDatabaseMissing('mobile_access_tokens', ['token_hash' => $plainTextToken]);
    }

    public function test_mobile_token_reuses_authorized_tenant_routes_without_trusting_the_route_id(): void
    {
        $user = User::factory()->create();
        $allowed = Organization::factory()->create();
        $denied = Organization::factory()->create();
        $this->membership($user, $allowed);
        $token = MobileAccessToken::issue($user, 'Test Android')['token'];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/organizations/{$allowed->id}/context")
            ->assertOk()
            ->assertJsonPath('organization_context.organization.id', $allowed->id);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/organizations/{$denied->id}/context")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_mobile_location_route_remains_scoped_to_the_authorized_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $this->membership($user, $organization);
        $otherLocation = RestaurantLocation::factory()->for($otherOrganization)->create();
        $token = MobileAccessToken::issue($user, 'Test Android')['token'];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/organizations/{$organization->id}/restaurant-locations/{$otherLocation->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_mobile_logout_revokes_only_the_current_token_and_subsequent_requests_are_denied(): void
    {
        $user = User::factory()->create();
        $token = MobileAccessToken::issue($user, 'Test iPhone')['token'];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/auth/session')
            ->assertOk();
        $this->assertNotNull(MobileAccessToken::query()->firstOrFail()->revoked_at);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/session')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'mobile_token_invalid');
    }

    public function test_expired_or_auth_version_invalidated_mobile_tokens_are_rejected_and_revoked(): void
    {
        $user = User::factory()->create();
        $issued = MobileAccessToken::issue($user, 'Test iPhone');
        $issued['accessToken']->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withHeader('Authorization', "Bearer {$issued['token']}")
            ->getJson('/api/v1/auth/session')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'mobile_token_expired');
        $this->assertNotNull($issued['accessToken']->refresh()->revoked_at);

        $versionIssued = MobileAccessToken::issue($user, 'Test Android');
        $user->increment('auth_version');
        $this->withHeader('Authorization', "Bearer {$versionIssued['token']}")
            ->getJson('/api/v1/auth/session')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'mobile_token_expired');
        $this->assertNotNull($versionIssued['accessToken']->refresh()->revoked_at);
    }

    public function test_mobile_login_uses_the_same_rate_limit_as_browser_login(): void
    {
        $email = 'mobile-limit-'.uniqid().'@example.test';
        $key = hash('sha256', $email).'|127.0.0.92';
        RateLimiter::clear($key);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.92'])
                ->postJson('/api/v1/mobile/auth/login', ['email' => $email, 'password' => 'not-the-password'])
                ->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.92'])
            ->postJson('/api/v1/mobile/auth/login', ['email' => $email, 'password' => 'not-the-password'])
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'too_many_requests');
    }

    public function test_mobile_token_cannot_mutate_lists_or_fall_back_to_a_browser_cookie(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($user, $organization);
        $token = MobileAccessToken::issue($user, 'Test Android')['token'];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/organizations/{$organization->id}/saved-lists", [
                'name' => 'Not allowed from this token',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'mobile_scope_forbidden');

        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version])
            ->withHeader('Authorization', 'Bearer cpm1.00000000-0000-0000-0000-000000000000.'.str_repeat('0', 64))
            ->getJson('/api/v1/auth/session')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'mobile_token_invalid');
    }
}
