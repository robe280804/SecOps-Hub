<?php

use App\Enums\ProjectAccessLevel;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use Database\Seeders\ProjectSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(LazilyRefreshDatabase::class);

it('creates an inactive project through its creator with nullable description', function () {
    $creator = User::factory()->create();

    $project = $creator->projects()->create(['name' => 'Assessment', 'type' => ProjectType::Company]);

    expect($project->fresh()->status)->toBe(ProjectStatus::Inactive);
    expect($project->fresh()->description)->toBeNull();
    expect($project->creator->is($creator))->toBeTrue();
    expect($creator->projects()->sole()->is($project))->toBeTrue();
});

it('persists and casts each project type', function (ProjectType $type) {
    $project = Project::factory()->create(['type' => $type]);

    expect($project->fresh()->type)->toBe($type);
})->with(ProjectType::cases());

it('supports active and archived factory states', function (string $state, ProjectStatus $status) {
    $project = Project::factory()->{$state}()->create();

    expect($project->fresh()->status)->toBe($status);
})->with([
    'active' => ['active', ProjectStatus::Active],
    'archived' => ['archived', ProjectStatus::Archived],
]);

it('rejects invalid persisted project enum values', function (string $field) {
    $project = Project::factory()->create();

    expect(fn () => DB::table('projects')->where('id', $project->id)->update([$field => 'invalid']))
        ->toThrow(QueryException::class);
})->with(['type', 'status']);

it('guards ownership against mass assignment', function () {
    $project = Project::factory()->create();
    $creatorId = $project->user_id;

    $project->fill(['name' => 'Updated', 'user_id' => User::factory()->create()->id]);
    $project->save();

    expect($project->fresh()->user_id)->toBe($creatorId);
});

it('rejects transferring project ownership through the model', function () {
    $project = Project::factory()->create();
    $originalCreator = $project->user_id;
    $project->creator()->associate(User::factory()->create());

    expect(fn () => $project->save())->toThrow(ValidationException::class);
    expect($project->fresh()->user_id)->toBe($originalCreator);
});

it('relates collaborators to their project and user with typed access levels', function () {
    $membership = ProjectMembership::factory()->contributor()->create();

    expect($membership->fresh()->access_level)->toBe(ProjectAccessLevel::Contributor);
    expect($membership->project->memberships()->sole()->is($membership))->toBeTrue();
    expect($membership->user->projectMemberships()->sole()->is($membership))->toBeTrue();
    expect($membership->project->user_id)->not->toBe($membership->user_id);
});

it('defaults memberships to viewer', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $membership = new ProjectMembership;
    $membership->project()->associate($project);
    $membership->user()->associate($user);

    $membership->save();

    expect($membership->fresh()->access_level)->toBe(ProjectAccessLevel::Viewer);
});

it('rejects duplicate collaborators', function () {
    $membership = ProjectMembership::factory()->create();

    expect(fn () => ProjectMembership::factory()->for($membership->project)->for($membership->user)->create())
        ->toThrow(QueryException::class);
    $this->assertDatabaseCount('project_memberships', 1);
});

it('rejects adding the creator as a collaborator', function () {
    $project = Project::factory()->create();

    expect(fn () => ProjectMembership::factory()->for($project)->for($project->creator, 'user')->create())
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('project_memberships', 0);
});

it('rejects changing a collaborator to the creator', function () {
    $membership = ProjectMembership::factory()->create();
    $originalUser = $membership->user_id;
    $membership->user()->associate($membership->project->creator);

    expect(fn () => $membership->save())->toThrow(ValidationException::class);
    expect($membership->fresh()->user_id)->toBe($originalUser);
});

it('restricts deleting owners at database level', function () {
    $project = Project::factory()->create();

    expect(fn () => $project->creator->delete())->toThrow(QueryException::class);
    $this->assertModelExists($project);
});

it('rejects moving a membership to a different project or user', function (string $field) {
    $membership = ProjectMembership::factory()->create();
    $before = $membership->fresh()->getRawOriginal();
    $membership->{$field} = $field === 'project_id' ? Project::factory()->create()->id : User::factory()->create()->id;

    expect(fn () => $membership->save())->toThrow(ValidationException::class);

    expect($membership->fresh()->getRawOriginal())->toBe($before);
})->with(['project_id', 'user_id']);

it('returns 403 when an admin attempts to delete a project owner', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();

    $this->actingAs($admin)->deleteJson('/api/v1/users/'.$project->user_id)->assertForbidden();

    $this->assertModelExists($project->creator);
    $this->assertModelExists($project);
});

it('removes memberships when a collaborator is deleted without deleting the project', function () {
    $membership = ProjectMembership::factory()->create();
    $project = $membership->project;

    $membership->user->delete();

    $this->assertModelMissing($membership);
    $this->assertModelExists($project);
});

it('removes memberships when a project is deleted without deleting its users', function () {
    $membership = ProjectMembership::factory()->create();
    $creator = $membership->project->creator;
    $collaborator = $membership->user;

    $membership->project->delete();

    $this->assertModelMissing($membership);
    $this->assertModelExists($creator);
    $this->assertModelExists($collaborator);
});

it('seeds demo projects for the configured admin without overwriting changes on rerun', function () {
    $admin = User::factory()->admin()->create();
    config(['admin.email' => $admin->email]);
    $this->seed(ProjectSeeder::class);
    $project = $admin->projects()->where('type', ProjectType::Personal)->firstOrFail();
    $project->update(['description' => 'Edited locally', 'status' => ProjectStatus::Active]);

    $this->seed(ProjectSeeder::class);

    $this->assertDatabaseCount('projects', 4);
    $this->assertDatabaseCount('users', 1);
    expect($project->fresh()->description)->toBe('Edited locally');
    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

it('does not seed demo projects without a configured admin', function () {
    config(['admin.email' => null]);

    expect(fn () => $this->seed(ProjectSeeder::class))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('projects', 0);
});

it('does not grant demo projects to a normal account', function () {
    $user = User::factory()->create();
    config(['admin.email' => $user->email]);

    expect(fn () => $this->seed(ProjectSeeder::class))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('projects', 0);
});

it('refuses demo seeding outside development environments', function () {
    $this->app->instance('env', 'production');

    expect(fn () => app(ProjectSeeder::class)->run())->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('projects', 0);
});
