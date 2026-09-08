<?php

use App\Models\User;
use App\Policies\UserPolicy;
use App\UserRole;

function policyUser(int $id, UserRole $role): User
{
    $user = new User(['role' => $role]);
    $user->id = $id;

    return $user;
}

test('admin permission matrix grants user management except self deletion', function () {
    $admin = policyUser(1, UserRole::Admin);
    $otherUser = policyUser(2, UserRole::Member);
    $policy = new UserPolicy;

    expect($policy->viewAny($admin))->toBeTrue()
        ->and($policy->create($admin))->toBeTrue()
        ->and($policy->view($admin, $otherUser))->toBeTrue()
        ->and($policy->update($admin, $otherUser))->toBeTrue()
        ->and($policy->delete($admin, $otherUser))->toBeTrue()
        ->and($policy->delete($admin, $admin))->toBeFalse();
});

test('member permission matrix grants access only to their own profile', function () {
    $member = policyUser(1, UserRole::Member);
    $otherUser = policyUser(2, UserRole::Member);
    $policy = new UserPolicy;

    expect($policy->viewAny($member))->toBeFalse()
        ->and($policy->create($member))->toBeFalse()
        ->and($policy->view($member, $member))->toBeTrue()
        ->and($policy->view($member, $otherUser))->toBeFalse()
        ->and($policy->update($member, $member))->toBeTrue()
        ->and($policy->update($member, $otherUser))->toBeFalse()
        ->and($policy->delete($member, $member))->toBeFalse()
        ->and($policy->delete($member, $otherUser))->toBeFalse();
});
