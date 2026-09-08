<?php

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('admin permission matrix grants user management except self deletion', function () {
    $admin = User::factory()->admin()->create();
    $otherUser = User::factory()->create();
    $policy = new UserPolicy;

    expect($policy->viewAny($admin))->toBeTrue()
        ->and($policy->create($admin))->toBeTrue()
        ->and($policy->view($admin, $otherUser))->toBeTrue()
        ->and($policy->update($admin, $otherUser))->toBeTrue()
        ->and($policy->delete($admin, $otherUser))->toBeTrue()
        ->and($policy->delete($admin, $admin))->toBeFalse();
});

test('normal user permission matrix grants access only to their own profile', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $policy = new UserPolicy;

    expect($policy->viewAny($user))->toBeFalse()
        ->and($policy->create($user))->toBeFalse()
        ->and($policy->view($user, $user))->toBeTrue()
        ->and($policy->view($user, $otherUser))->toBeFalse()
        ->and($policy->update($user, $user))->toBeTrue()
        ->and($policy->update($user, $otherUser))->toBeFalse()
        ->and($policy->delete($user, $user))->toBeFalse()
        ->and($policy->delete($user, $otherUser))->toBeFalse();
});
