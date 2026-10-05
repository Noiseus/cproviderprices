<?php

namespace Tests\Feature;

use App\Enums\PreferredLanguage;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PreferredLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_is_the_default_authenticated_preference(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version])
            ->getJson('/api/v1/auth/session')
            ->assertOk()
            ->assertJsonPath('session.user.preferred_language', 'en');

        $this->assertSame(PreferredLanguage::English, $user->refresh()->preferred_language);
    }

    public function test_spanish_preference_persists_without_changing_session_or_membership(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $membership = OrganizationMembership::factory()->for($user)->for($organization)->create();

        $response = $this->actingAs($user)
            ->withSession(['auth_version' => $user->auth_version, '_token' => 'language-preference-test-token'])
            ->withHeader('X-CSRF-TOKEN', 'language-preference-test-token')
            ->patchJson('/api/v1/auth/preferences', ['preferred_language' => 'es']);

        $response->assertOk()
            ->assertJsonPath('session.user.id', $user->id)
            ->assertJsonPath('session.user.preferred_language', 'es')
            ->assertJsonPath('session.organizations.0.id', $organization->id);
        $this->assertSame(PreferredLanguage::Spanish, $user->refresh()->preferred_language);
        $this->assertDatabaseHas('organization_memberships', ['id' => $membership->id, 'user_id' => $user->id]);
    }

    public function test_unsupported_preference_uses_the_existing_stable_validation_code(): void
    {
        $user = User::factory()->create(['preferred_language' => PreferredLanguage::English]);

        $this->actingAs($user)
            ->withSession(['auth_version' => $user->auth_version, '_token' => 'language-preference-test-token'])
            ->withHeader('X-CSRF-TOKEN', 'language-preference-test-token')
            ->patchJson('/api/v1/auth/preferences', ['preferred_language' => 'fr'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.fields.preferred_language.0', 'The selected preferred language is invalid.');

        $this->assertSame(PreferredLanguage::English, $user->refresh()->preferred_language);
    }
}
