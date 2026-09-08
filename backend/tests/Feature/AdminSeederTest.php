<?php

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['admin.name' => 'Administrator', 'admin.email' => 'admin@example.com', 'admin.password' => 'StrongPassword123!']);
});

it('creates an admin from configuration through the default seeder', function () {
    $this->seed(DatabaseSeeder::class);

    $user = User::query()->sole();
    expect($user->email)->toBe('admin@example.com')
        ->and($user->name)->toBe('Administrator')
        ->and($user->hasRole(UserRole::Admin))->toBeTrue()
        ->and(Hash::check('StrongPassword123!', $user->password))->toBeTrue()
        ->and($user->password)->not->toBe('StrongPassword123!');
});

it('does not duplicate admins or overwrite their credentials on reruns', function () {
    $this->seed(AdminSeeder::class);
    $passwordHash = User::query()->sole()->password;
    config(['admin.password' => 'DifferentPassword123!', 'admin.name' => 'Changed']);

    $this->seed(AdminSeeder::class);

    $user = User::query()->sole();
    expect($user->password)->toBe($passwordHash)->and($user->name)->toBe('Administrator');
    $this->assertDatabaseCount('model_has_roles', 1);
});

it('rejects missing or invalid credentials without creating a user', function (string $key, mixed $value) {
    config(["admin.$key" => $value]);

    expect(fn () => $this->seed(AdminSeeder::class))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('users', 0);
})->with([
    'missing email' => ['email', null],
    'invalid email' => ['email', 'invalid'],
    'missing password' => ['password', null],
    'weak password' => ['password', 'password'],
]);

it('does not silently promote an existing normal user', function () {
    $user = User::factory()->create(['email' => 'admin@example.com']);
    $passwordHash = $user->password;

    expect(fn () => $this->seed(AdminSeeder::class))->toThrow(RuntimeException::class);
    expect($user->fresh()->hasRole(UserRole::Admin))->toBeFalse()
        ->and($user->fresh()->password)->toBe($passwordHash);
    $this->assertDatabaseCount('users', 1);
});
