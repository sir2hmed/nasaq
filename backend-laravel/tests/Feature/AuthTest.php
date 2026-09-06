<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_the_session_persists(): void
    {
        $response = $this->withHeaders($this->spaHeaders())->postJson('/api/auth/register', [
            'name' => '  Asrar Student  ',
            'email' => 'STUDENT@EXAMPLE.COM',
            'password' => 'NasaqPass2026',
            'password_confirmation' => 'NasaqPass2026',
            'preferred_locale' => 'ar',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.user.name', 'Asrar Student')
            ->assertJsonPath('data.user.email', 'student@example.com')
            ->assertJsonPath('data.user.preferred_locale', 'ar')
            ->assertJsonPath('errors', null)
            ->assertJsonMissingPath('data.user.password');

        $this->assertAuthenticated('web');
        $this->assertDatabaseHas('users', [
            'email' => 'student@example.com',
            'preferred_locale' => 'ar',
        ]);
        $this->assertTrue(Hash::check(
            'NasaqPass2026',
            User::query()->where('email', 'student@example.com')->value('password'),
        ));

        $this->withHeaders($this->spaHeaders())->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'student@example.com');
    }

    public function test_registration_returns_the_standard_validation_envelope(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->withHeaders($this->spaHeaders())->postJson('/api/auth/register', [
            'name' => '',
            'email' => 'taken@example.com',
            'password' => 'weak',
            'password_confirmation' => 'different',
            'preferred_locale' => 'fr',
        ])->assertUnprocessable()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message', 'The submitted data is invalid.')
            ->assertJsonValidationErrors(['name', 'email', 'password', 'preferred_locale']);
    }

    public function test_user_can_login_read_their_profile_and_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'password' => Hash::make('MemberPass2026'),
            'preferred_locale' => 'en',
        ]);

        $this->withHeaders($this->spaHeaders())->postJson('/api/auth/login', [
            'email' => 'member@example.com',
            'password' => 'MemberPass2026',
            'remember' => true,
        ])->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.preferred_locale', 'en');

        $this->assertAuthenticatedAs($user, 'web');

        $this->withHeaders($this->spaHeaders())->patchJson('/api/auth/locale', [
            'preferred_locale' => 'ar',
        ])->assertOk()
            ->assertJsonPath('data.user.preferred_locale', 'ar');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'preferred_locale' => 'ar',
        ]);

        $this->withHeaders($this->spaHeaders())->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertGuest('web');
        Auth::forgetGuards();
        $this->withHeaders($this->spaHeaders())->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Authentication is required.');
    }

    public function test_invalid_credentials_do_not_start_a_session(): void
    {
        User::factory()->create([
            'email' => 'member@example.com',
            'password' => Hash::make('CorrectPass2026'),
        ]);

        $this->withHeaders($this->spaHeaders())->postJson('/api/auth/login', [
            'email' => 'member@example.com',
            'password' => 'WrongPass2026',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertGuest('web');
    }

    public function test_protected_auth_routes_reject_guests_with_json(): void
    {
        $this->withHeaders($this->spaHeaders())->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertExactJson([
                'data' => null,
                'message' => 'Authentication is required.',
                'errors' => null,
            ]);
    }

    public function test_sanctum_csrf_cookie_endpoint_is_available(): void
    {
        $this->withHeaders($this->spaHeaders())->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    /** @return array<string, string> */
    private function spaHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ];
    }
}
