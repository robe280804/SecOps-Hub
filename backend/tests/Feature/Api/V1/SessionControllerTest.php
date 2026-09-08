<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

uses(LazilyRefreshDatabase::class);

it('signs in with a rotated session and no API token', function () {
    $user = User::factory()->create(['password' => 'SecretPassword123!']);
    $this->withSession(['previous' => true]);
    $previousId = session()->getId();

    $this->postJson('/api/v1/session', ['email' => $user->email, 'password' => 'SecretPassword123!'])
        ->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonMissingPath('token')
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->assertAuthenticatedAs($user, 'web');
    expect(session()->getId())->not->toBe($previousId);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    Auth::forgetGuards();
    $this->withHeader('Origin', 'http://localhost:5173')->getJson('/api/v1/me')
        ->assertOk()->assertJsonPath('data.id', $user->id);
});

it('returns 422 for incorrect credentials without creating a session login', function () {
    $this->postJson('/api/v1/session', ['email' => 'unknown@example.com', 'password' => 'wrong'])
        ->assertUnprocessable()->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');
    $this->assertGuest('web');
});

it('does not accept bearer tokens as browser sessions', function () {
    $user = User::factory()->create();
    $token = $user->createToken('script')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/v1/session')->assertUnauthorized();
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

it('protects stateful API writes with CSRF', function () {
    $this->app->instance('env', 'local');

    $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/users')
        ->assertStatus(419);
});

it('validates required session credentials', function () {
    $this->postJson('/api/v1/session')->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
});

it('invalidates the session on logout', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'web')->withSession(['private-data' => 'value']);
    $previousId = session()->getId();

    $this->deleteJson('/api/v1/session')->assertNoContent()->assertSessionMissing('private-data');

    $this->assertGuest('web');
    expect(session()->getId())->not->toBe($previousId);
    Auth::forgetGuards();
    $this->withHeader('Origin', 'http://localhost:5173')->getJson('/api/v1/me')->assertUnauthorized();
});

it('rejects session mutations without CSRF when test bypass is disabled', function () {
    $this->app->instance('env', 'local');

    $this->postJson('/api/v1/session', ['email' => 'analyst@example.com', 'password' => 'wrong'])
        ->assertStatus(419);
});

it('accepts the encrypted XSRF cookie header for browser login', function () {
    $user = User::factory()->create(['password' => 'SecretPassword123!']);
    $this->app->instance('env', 'local');
    $csrf = $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
    $cookie = $csrf->getCookie('XSRF-TOKEN', false);

    $this->withHeader('X-XSRF-TOKEN', $cookie->getValue())
        ->postJson('/api/v1/session', ['email' => $user->email, 'password' => 'SecretPassword123!'])
        ->assertOk();
    $this->assertAuthenticatedAs($user, 'web');
});

it('throttles repeated browser logins', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/session', ['email' => 'unknown@example.com', 'password' => 'wrong'])->assertUnprocessable();
    }
    $this->postJson('/api/v1/session', ['email' => 'unknown@example.com', 'password' => 'wrong'])
        ->assertTooManyRequests()->assertHeader('Retry-After');
});

it('does not expose exception details in API errors even with debug enabled', function () {
    config(['app.debug' => true]);
    Route::get('/api/v1/test-error', fn () => throw new RuntimeException('private database detail'));

    $this->getJson('/api/v1/test-error')->assertStatus(500)
        ->assertExactJson(['message' => 'The service is unavailable. Please try again later.']);
});

it('only grants credentialed CORS to the configured frontend', function () {
    $this->withHeader('Origin', 'http://localhost:5173')->getJson('/sanctum/csrf-cookie')
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
    $this->withHeader('Origin', 'https://untrusted.example')->getJson('/sanctum/csrf-cookie')
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
});
