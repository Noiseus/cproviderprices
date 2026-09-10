<?php

namespace Tests\Feature;

use App\Enums\EntitlementKey;
use App\Enums\InvitationStatus;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invited_owner_activates_only_the_authoritative_organization_and_role(): void
    {
        $token = Str::random(64);
        $password = Str::random(24);
        $organization = Organization::factory()->create(['slug' => 'intended-restaurant']);
        $this->enableMembershipActivation($organization);
        $otherOrganization = Organization::factory()->create();
        $invitation = Invitation::factory()->for($organization)->withPlaintextToken($token)->create([
            'intended_email' => 'invited-owner@example.test',
            'intended_role' => Role::OrganizationOwner,
        ]);

        $response = $this->postJson('/api/v1/auth/invitations/accept', [
            'token' => $token,
            'name' => 'Invited Owner',
            'email' => $invitation->intended_email,
            'password' => $password,
            'password_confirmation' => $password,
            'organization_id' => $otherOrganization->id,
            'role' => Role::Viewer->value,
        ]);

        $response->assertOk()->assertJsonPath('destination', '/o/intended-restaurant/dashboard');
        $user = User::query()->where('email', $invitation->intended_email)->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => Role::OrganizationOwner->value,
            'status' => MembershipStatus::Active->value,
        ]);
        $this->assertDatabaseMissing('organization_memberships', ['organization_id' => $otherOrganization->id]);
        $this->assertSame(InvitationStatus::Accepted, $invitation->refresh()->status);
        $response->assertCookieNotExpired((string) config('session.cookie'));
        $this->getJson('/api/v1/auth/session')
            ->assertOk()
            ->assertJsonPath('session.user.id', $user->id)
            ->assertJsonPath('session.organizations.0.id', $organization->id);
    }

    public function test_reused_invitation_token_is_rejected(): void
    {
        $token = Str::random(64);
        $password = Str::random(24);
        $invitation = Invitation::factory()->withPlaintextToken($token)->create();
        $this->enableMembershipActivation($invitation->organization);
        $payload = ['token' => $token, 'name' => 'New User', 'password' => $password, 'password_confirmation' => $password];

        $this->postJson('/api/v1/auth/invitations/accept', $payload)->assertOk();
        $this->postJson('/api/v1/auth/invitations/accept', $payload)
            ->assertConflict()->assertJsonPath('error.code', 'invitation_already_used');
    }

    public function test_expired_invitation_has_a_clear_terminal_response(): void
    {
        $token = Str::random(64);
        Invitation::factory()->withPlaintextToken($token)->expired()->create();

        $this->getJson('/api/v1/auth/invitations/'.$token)
            ->assertStatus(410)->assertJsonPath('error.code', 'invitation_expired');
    }

    public function test_invitation_email_tampering_is_rejected(): void
    {
        $token = Str::random(64);
        $password = Str::random(24);
        Invitation::factory()->withPlaintextToken($token)->create(['intended_email' => 'intended@example.test']);

        $this->postJson('/api/v1/auth/invitations/accept', [
            'token' => $token, 'name' => 'Wrong Email', 'email' => 'attacker@example.test',
            'password' => $password, 'password_confirmation' => $password,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'invitation_identity_mismatch');
    }

    public function test_suspended_user_cannot_login_or_accept_an_invitation(): void
    {
        $password = Str::random(24);
        $user = User::factory()->create([
            'email' => 'suspended@example.test', 'password' => Hash::make($password), 'status' => UserStatus::Suspended,
        ]);
        $token = Str::random(64);
        Invitation::factory()->withPlaintextToken($token)->create(['intended_email' => $user->email]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password])
            ->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
        $this->postJson('/api/v1/auth/invitations/accept', [
            'token' => $token, 'name' => $user->name, 'password' => $password, 'password_confirmation' => $password,
        ])->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
    }

    public function test_login_uses_a_safe_authorized_destination_and_logout_ends_the_session(): void
    {
        $password = Str::random(24);
        $user = User::factory()->create(['password' => Hash::make($password)]);
        $organization = Organization::factory()->create(['slug' => 'safe-org']);
        OrganizationMembership::factory()->for($user)->for($organization)->create();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => $password, 'intended_destination' => '//attacker.example/steal',
        ])->assertOk()->assertJsonPath('destination', '/o/safe-org/dashboard');
        $this->getJson('/api/v1/auth/session')->assertOk();
        $this->deleteJson('/api/v1/auth/session')->assertOk();
        $this->getJson('/api/v1/auth/session')->assertUnauthorized();
    }

    public function test_forgot_password_response_does_not_enumerate_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.test']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_invalid_responses_do_not_enumerate_accounts(): void
    {
        $user = User::factory()->create();
        $password = Str::random(24);
        $payload = ['token' => Str::random(64), 'password' => $password, 'password_confirmation' => $password];
        $known = $this->postJson('/api/v1/auth/reset-password', [...$payload, 'email' => $user->email]);
        $unknown = $this->postJson('/api/v1/auth/reset-password', [...$payload, 'email' => 'missing@example.test']);

        $known->assertUnprocessable();
        $unknown->assertUnprocessable();
        $this->assertSame($known->json('error.code'), $unknown->json('error.code'));
        $this->assertSame($known->json('error.message'), $unknown->json('error.message'));
    }

    public function test_password_reset_invalidates_an_existing_session(): void
    {
        $oldPassword = Str::random(24);
        $newPassword = Str::random(24);
        $user = User::factory()->create(['password' => Hash::make($oldPassword)]);
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->for($user)->for($organization)->create();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $oldPassword])->assertOk();
        $token = Password::createToken($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $token, 'password' => $newPassword, 'password_confirmation' => $newPassword,
        ])->assertOk();
        $this->getJson('/api/v1/auth/session')
            ->assertUnauthorized()->assertJsonPath('error.code', 'session_invalidated');
    }

    public function test_suspended_organization_invitation_is_denied(): void
    {
        $organization = Organization::factory()->create(['status' => OrganizationStatus::Suspended]);
        $token = Str::random(64);
        Invitation::factory()->for($organization)->withPlaintextToken($token)->create();

        $this->getJson('/api/v1/auth/invitations/'.$token)
            ->assertStatus(423)->assertJsonPath('error.code', 'organization_suspended');
    }

    public function test_public_registration_endpoint_does_not_exist(): void
    {
        $this->postJson('/api/v1/auth/register', [])->assertNotFound();
    }

    public function test_email_verification_notification_and_signed_link_flow(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);

        $this->postJson('/api/v1/auth/email/verification-notification')->assertOk();
        Notification::assertSentTo($user, VerifyEmail::class);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ], false);
        $this->getJson($url)->assertOk();
        $this->assertTrue($user->refresh()->hasVerifiedEmail());
    }

    public function test_suspending_a_user_invalidates_an_established_session(): void
    {
        $password = Str::random(24);
        $user = User::factory()->create(['password' => Hash::make($password)]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password])->assertOk();
        $user->update(['status' => UserStatus::Suspended]);

        $this->getJson('/api/v1/auth/session')
            ->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
    }

    public function test_cookie_authenticated_state_changes_require_csrf_outside_testing(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/v1/auth/login', 'POST'));
        $this->assertContains('web', $route->gatherMiddleware());

        $request = Request::create('/api/v1/auth/login', 'POST');
        $session = app('session')->driver('array');
        $session->start();
        $session->regenerateToken();
        $request->setLaravelSession($session);
        $middleware = new class(app(), app('encrypter')) extends VerifyCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };

        $this->expectException(TokenMismatchException::class);
        $middleware->handle($request, fn () => response()->noContent());
    }

    private function enableMembershipActivation(Organization $organization): void
    {
        $plan = Plan::factory()->create();
        PlanEntitlement::factory()->for($plan)->create([
            'key' => EntitlementKey::MaxUsers,
            'value' => ['limit' => 10],
        ]);
        Subscription::factory()->for($organization)->for($plan)->create();
    }
}
