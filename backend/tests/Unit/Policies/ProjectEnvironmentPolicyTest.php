<?php

use App\Enums\EnvironmentDesiredState;
use App\Enums\EnvironmentStatus;
use App\Enums\ProjectAccessLevel;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Policies\ProjectEnvironmentPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('owners can read environments in every project state and write only outside the archive', function (ProjectStatus $status) {
    $environment = ProjectEnvironment::factory()->for(Project::factory()->state(['status' => $status]))->create();
    $project = $environment->project;
    $owner = $project->creator;
    $policy = app(ProjectEnvironmentPolicy::class);

    expect($policy->viewAny($owner, $project)->allowed())->toBeTrue();
    expect($policy->view($owner, $environment)->allowed())->toBeTrue();
    expect($policy->create($owner, $project)->allowed())->toBe($status !== ProjectStatus::Archived);
    expect($policy->update($owner, $environment)->allowed())->toBe($status !== ProjectStatus::Archived);
    expect($policy->delete($owner, $environment)->allowed())->toBe($status !== ProjectStatus::Archived);
})->with(ProjectStatus::cases());

test('collaborators cannot administer or inspect environment configuration', function (ProjectAccessLevel $access, ProjectStatus $status) {
    $environment = ProjectEnvironment::factory()->for(Project::factory()->state(['status' => $status]))->create();
    $membership = ProjectMembership::factory()->for($environment->project)->create(['access_level' => $access]);
    $policy = app(ProjectEnvironmentPolicy::class);

    expect($policy->viewAny($membership->user, $environment->project)->denied())->toBeTrue();
    expect($policy->view($membership->user, $environment)->denied())->toBeTrue();
    expect($policy->create($membership->user, $environment->project)->denied())->toBeTrue();
    expect($policy->update($membership->user, $environment)->denied())->toBeTrue();
    expect($policy->delete($membership->user, $environment)->denied())->toBeTrue();
    expect($policy->view($membership->user, $environment)->status())->toBeNull();
})->with(ProjectAccessLevel::cases())->with(ProjectStatus::cases());

test('unrelated users and global admins cannot discover environments', function (string $role) {
    $environment = ProjectEnvironment::factory()->create();
    $user = User::factory()->create();
    $user->syncRoles($role);
    $policy = app(ProjectEnvironmentPolicy::class);

    expect($policy->viewAny($user, $environment->project)->status())->toBe(404);
    expect($policy->view($user, $environment)->status())->toBe(404);
    expect($policy->create($user, $environment->project)->status())->toBe(404);
    expect($policy->update($user, $environment)->status())->toBe(404);
    expect($policy->delete($user, $environment)->status())->toBe(404);
})->with(['user', 'admin']);

test('pending operations prevent environment edits', function (EnvironmentStatus $status, bool $allowed) {
    $environment = ProjectEnvironment::factory()->create(['status' => $status]);
    $policy = app(ProjectEnvironmentPolicy::class);

    expect($policy->update($environment->project->creator, $environment)->allowed())->toBe($allowed);
})->with([
    [EnvironmentStatus::Inactive, true],
    [EnvironmentStatus::Provisioning, false],
    [EnvironmentStatus::Stopped, true],
    [EnvironmentStatus::Starting, false],
    [EnvironmentStatus::Ready, true],
    [EnvironmentStatus::Stopping, false],
    [EnvironmentStatus::Error, true],
    [EnvironmentStatus::Deleting, false],
]);

test('a requested deletion prevents further edits regardless of observed state', function () {
    $environment = ProjectEnvironment::factory()->create(['desired_state' => EnvironmentDesiredState::Deleted]);

    expect(app(ProjectEnvironmentPolicy::class)->update($environment->project->creator, $environment)->status())->toBe(409);
});

test('any provisioning evidence prevents direct deletion', function (array $attributes) {
    $environment = ProjectEnvironment::factory()->create($attributes);

    expect(app(ProjectEnvironmentPolicy::class)->delete($environment->project->creator, $environment)->status())->toBe(409);
})->with([
    'desired running' => [['desired_state' => EnvironmentDesiredState::Running]],
    'deletion requested' => [['desired_state' => EnvironmentDesiredState::Deleted]],
    'non-inactive state' => [['status' => EnvironmentStatus::Stopped]],
    'previous generation' => [['runtime_generation' => 1]],
    'runtime reference' => [['runtime_reference' => 'container-1']],
    'runtime observation' => [['runtime_status' => 'exited']],
    'persistent workspace' => [['workspace_reference' => 'volume-1']],
]);

test('observing an inactive environment does not imply that it has been provisioned', function () {
    $environment = ProjectEnvironment::factory()->create(['last_observed_at' => '2026-01-01 00:00:00']);

    expect(app(ProjectEnvironmentPolicy::class)->delete($environment->project->creator, $environment)->allowed())->toBeTrue();
});

test('revoked collaborators receive a hidden environment response on the next authorization', function () {
    $environment = ProjectEnvironment::factory()->create();
    $membership = ProjectMembership::factory()->for($environment->project)->create();
    $user = $membership->user;
    $policy = app(ProjectEnvironmentPolicy::class);
    expect($policy->view($user, $environment)->status())->toBeNull();

    $membership->delete();

    expect($policy->view($user, $environment)->status())->toBe(404);
});
