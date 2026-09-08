<?php

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

describe('index', function () {
    it('returns 401 when unauthenticated', function () {
        $this->getJson('/api/v1/users')->assertUnauthorized();
    });

    it('returns 403 to a member', function () {
        $member = User::factory()->create();

        $this->actingAs($member)->getJson('/api/v1/users')->assertForbidden();
    });

    it('returns a paginated user list to an admin', function () {
        $admin = User::factory()->admin()->create(['name' => 'Administrator']);
        User::factory()->create(['name' => 'Security Analyst']);

        $this->actingAs($admin)
            ->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data', 'links', 'meta']);
    });
});

describe('store', function () {
    it('creates a member when an admin submits valid data', function () {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'New Analyst',
            'email' => 'new.analyst@example.com',
            'password' => 'SecurePassword1!',
            'password_confirmation' => 'SecurePassword1!',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.email', 'new.analyst@example.com')
            ->assertJsonPath('data.role', 'member');
        $this->assertDatabaseHas('users', [
            'email' => 'new.analyst@example.com',
            'role' => UserRole::Member->value,
        ]);
        expect(Hash::check(
            'SecurePassword1!',
            User::where('email', 'new.analyst@example.com')->value('password'),
        ))->toBeTrue();
    });

    it('returns 403 when a member creates a user', function () {
        $member = User::factory()->create();

        $this->actingAs($member)->postJson('/api/v1/users', [
            'name' => 'New Analyst',
            'email' => 'new.analyst@example.com',
            'password' => 'SecurePassword1!',
            'password_confirmation' => 'SecurePassword1!',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'new.analyst@example.com']);
    });

    it('returns 422 for invalid user data', function () {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
            'role' => 'owner',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password', 'role']);
    });
});

describe('show', function () {
    it('returns a member own profile', function () {
        $member = User::factory()->create();

        $this->actingAs($member)
            ->getJson("/api/v1/users/{$member->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $member->id);
    });

    it('returns 403 when a member views another user', function () {
        $member = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($member)
            ->getJson("/api/v1/users/{$otherUser->id}")
            ->assertForbidden();
    });
});

describe('update', function () {
    it('lets a member update their profile and ignores unexpected attributes', function () {
        $member = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($member)->patchJson("/api/v1/users/{$member->id}", [
            'name' => 'Updated Analyst',
            'email_verified_at' => now()->toISOString(),
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Analyst')
            ->assertJsonPath('data.email_verified_at', null);
        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'name' => 'Updated Analyst',
            'email_verified_at' => null,
        ]);
    });

    it('returns 422 when a member attempts to change their role', function () {
        $member = User::factory()->create();

        $this->actingAs($member)
            ->patchJson("/api/v1/users/{$member->id}", ['role' => UserRole::Admin->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'role' => UserRole::Member->value,
        ]);
    });

    it('lets an admin change another user role', function () {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $this->actingAs($admin)
            ->patchJson("/api/v1/users/{$member->id}", ['role' => UserRole::Admin->value])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'role' => UserRole::Admin->value,
        ]);
    });

    it('returns 403 when a member updates another user', function () {
        $member = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($member)
            ->patchJson("/api/v1/users/{$otherUser->id}", ['name' => 'Unauthorized'])
            ->assertForbidden();
        $this->assertDatabaseMissing('users', [
            'id' => $otherUser->id,
            'name' => 'Unauthorized',
        ]);
    });
});

describe('destroy', function () {
    it('lets an admin delete another user', function () {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $member->createToken('web-client');

        $this->actingAs($admin)
            ->deleteJson("/api/v1/users/{$member->id}")
            ->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $member->id]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $member->id,
        ]);
    });

    it('returns 403 when an admin deletes their own account', function () {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/users/{$admin->id}")
            ->assertForbidden();
        $this->assertModelExists($admin);
    });
});
