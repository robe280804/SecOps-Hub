<?php

use App\Enums\ProjectAccessLevel;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('owners can read and manage projects in every state but cannot permanently delete them', function (ProjectStatus $status) {
    $project = Project::factory()->create(['status' => $status]);
    $owner = $project->creator;
    $policy = new ProjectPolicy;

    expect($policy->viewAny($owner))->toBeTrue();
    expect($policy->create($owner))->toBeTrue();
    expect($policy->view($owner, $project)->allowed())->toBeTrue();
    expect($policy->update($owner, $project)->allowed())->toBeTrue();
    expect($policy->delete($owner, $project)->allowed())->toBeTrue();
    expect($policy->forceDelete($owner, $project))->toBeFalse();
})->with(ProjectStatus::cases());

test('collaborators can read every project state but cannot manage the project', function (ProjectAccessLevel $access, ProjectStatus $status) {
    $project = Project::factory()->create(['status' => $status]);
    $membership = ProjectMembership::factory()->for($project)->create(['access_level' => $access]);
    $user = $membership->user;
    $policy = new ProjectPolicy;

    expect($policy->view($user, $project)->allowed())->toBeTrue();
    expect($policy->update($user, $project)->denied())->toBeTrue();
    expect($policy->update($user, $project)->status())->toBeNull();
    expect($policy->delete($user, $project)->denied())->toBeTrue();
    expect($policy->forceDelete($user, $project))->toBeFalse();
})->with(ProjectAccessLevel::cases())->with(ProjectStatus::cases());

test('unrelated users including global admins cannot discover or manage projects', function (string $role) {
    $user = User::factory()->create();
    $user->syncRoles($role);
    $project = Project::factory()->create();
    $policy = new ProjectPolicy;

    expect($policy->viewAny($user))->toBeTrue();
    expect($policy->create($user))->toBeTrue();
    expect($policy->view($user, $project)->status())->toBe(404);
    expect($policy->update($user, $project)->status())->toBe(404);
    expect($policy->delete($user, $project)->status())->toBe(404);
    expect($policy->forceDelete($user, $project))->toBeFalse();
})->with(['user', 'admin']);
