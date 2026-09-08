<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

it('issues a token when the credentials are valid', function () {
    $user = User::factory()->create([
        'email' => 'analyst@example.com',
        'password' => 'password',
    ]);

    $response = $this->postJson('/api/v1/login', [
        'email' => 'analyst@example.com',
        'password' => 'password',
        'device_name' => 'web-client',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', 'analyst@example.com')
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonStructure(['token']);
    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'name' => 'web-client',
    ]);
});

it('returns 422 without revealing which credential is incorrect', function () {
    User::factory()->create([
        'email' => 'analyst@example.com',
        'password' => 'correct-password',
    ]);

    $response = $this->postJson('/api/v1/login', [
        'email' => 'analyst@example.com',
        'password' => 'incorrect-password',
        'device_name' => 'web-client',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email'])
        ->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('returns 422 when required login fields are missing', function () {
    $this->postJson('/api/v1/login')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password', 'device_name']);
});

it('returns the authenticated user', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.role', 'admin');
});

it('returns 401 when no token is provided', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('revokes the current token on logout', function () {
    $user = User::factory()->create();
    $plainTextToken = $user->createToken('web-client')->plainTextToken;

    $this->withToken($plainTextToken)
        ->deleteJson('/api/v1/logout')
        ->assertNoContent();

    $this->assertDatabaseCount('personal_access_tokens', 0);
    Auth::forgetGuards();
    $this->withToken($plainTextToken)
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

it('returns 429 after five failed login attempts for the same identity and ip', function () {
    $payload = [
        'email' => 'missing@example.com',
        'password' => 'incorrect-password',
        'device_name' => 'web-client',
    ];

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/login', $payload)->assertUnprocessable();
    }

    $this->postJson('/api/v1/login', $payload)->assertTooManyRequests();
});

it('stores passwords using a one-way hash', function () {
    $user = User::factory()->create(['password' => 'Plaintext1!']);

    expect($user->password)
        ->not->toBe('Plaintext1!')
        ->and(Hash::check('Plaintext1!', $user->password))->toBeTrue();
});
