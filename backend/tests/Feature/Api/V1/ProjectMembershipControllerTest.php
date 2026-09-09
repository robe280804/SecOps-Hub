<?php

use App\Enums\ProjectAccessLevel;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(LazilyRefreshDatabase::class);

it('returns 401 for unauthenticated membership requests', function (string $method, bool $individual) {
    $membership = ProjectMembership::factory()->create();
    $path = '/api/v1/projects/'.$membership->project_id.'/memberships'.($individual ? '/'.$membership->id : '');

    $this->json($method, $path, ['user_id' => $membership->user_id, 'access_level' => 'contributor'])->assertUnauthorized();

    $this->assertDatabaseHas('project_memberships', ['id' => $membership->id, 'access_level' => 'viewer']);
})->with([['GET', false], ['POST', false], ['PATCH', true], ['DELETE', true]]);

it('returns 404 for membership operations on unrelated projects', function (string $method, bool $individual, string $role) {
    $user = User::factory()->create();
    $user->syncRoles($role);
    $membership = ProjectMembership::factory()->create();
    $path = '/api/v1/projects/'.$membership->project_id.'/memberships'.($individual ? '/'.$membership->id : '');

    $this->actingAs($user)->json($method, $path, ['user_id' => $membership->user_id, 'access_level' => 'contributor'])->assertNotFound();

    $this->assertDatabaseHas('project_memberships', ['id' => $membership->id, 'access_level' => 'viewer']);
})->with([['GET', false], ['POST', false], ['PATCH', true], ['DELETE', true]])->with(['user', 'admin']);

it('returns 403 when a collaborator tries to manage memberships', function (string $method, bool $individual, ProjectAccessLevel $access) {
    $membership = ProjectMembership::factory()->create(['access_level' => $access]);
    $path = '/api/v1/projects/'.$membership->project_id.'/memberships'.($individual ? '/'.$membership->id : '');

    $this->actingAs($membership->user)->json($method, $path, ['user_id' => $membership->user_id, 'access_level' => 'viewer'])->assertForbidden();

    $this->assertDatabaseHas('project_memberships', ['id' => $membership->id, 'access_level' => $access->value]);
})->with([['GET', false], ['POST', false], ['PATCH', true], ['DELETE', true]])->with(ProjectAccessLevel::cases());

it('returns 404 when a membership ID belongs to a different project owned by the caller', function (string $method) {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner, 'creator')->create();
    $otherProject = Project::factory()->for($owner, 'creator')->create();
    $membership = ProjectMembership::factory()->for($otherProject)->create();

    $this->actingAs($owner)->json($method, '/api/v1/projects/'.$project->id.'/memberships/'.$membership->id, ['access_level' => 'contributor'])
        ->assertNotFound();

    $this->assertDatabaseHas('project_memberships', ['id' => $membership->id, 'project_id' => $otherProject->id, 'access_level' => 'viewer']);
})->with(['PATCH', 'DELETE']);

describe('index', function () {
    it('lists only this project members with safe user fields and deterministic pagination', function () {
        $project = Project::factory()->archived()->create();
        $members = ProjectMembership::factory()->count(16)->for($project)->create();
        ProjectMembership::factory()->create();

        $response = $this->actingAs($project->creator)->getJson('/api/v1/projects/'.$project->id.'/memberships?page=2')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 16)
            ->assertJsonPath('data.0.id', $members->last()->id);

        expect(array_keys($response->json('data.0')))->toBe(['id', 'project_id', 'access_level', 'user', 'created_at', 'updated_at']);
        expect(array_keys($response->json('data.0.user')))->toBe(['id', 'name', 'email']);
    });
});

describe('store', function () {
    it('adds an existing user as viewer by default and grants project access', function () {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($project->creator)->postJson('/api/v1/projects/'.$project->id.'/memberships', [
            'user_id' => $user->id, 'id' => 999999, 'created_at' => '2000-01-01', 'role' => 'admin',
        ])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.access_level', 'viewer')->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseHas('project_memberships', ['project_id' => $project->id, 'user_id' => $user->id, 'access_level' => 'viewer']);
        $this->assertDatabaseMissing('project_memberships', ['id' => 999999]);
        $this->assertDatabaseCount('users', 2);
        expect($user->fresh()->hasRole('admin'))->toBeFalse();
        $this->actingAs($user)->getJson('/api/v1/projects/'.$project->id)->assertOk();
    });

    it('adds a contributor when selected', function () {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($project->creator)->postJson('/api/v1/projects/'.$project->id.'/memberships', [
            'user_id' => $user->id, 'access_level' => 'contributor',
        ])->assertCreated()->assertJsonPath('data.access_level', 'contributor');

        $this->assertDatabaseHas('project_memberships', ['project_id' => $project->id, 'user_id' => $user->id, 'access_level' => 'contributor']);
    });

    it('returns 422 when adding the creator', function () {
        $project = Project::factory()->create();

        $this->actingAs($project->creator)->postJson('/api/v1/projects/'.$project->id.'/memberships', ['user_id' => $project->user_id])
            ->assertUnprocessable()->assertJsonValidationErrors(['user_id' => 'The project creator cannot be added as a collaborator.']);

        $this->assertDatabaseCount('project_memberships', 0);
    });

    it('returns 422 for duplicate assignments without changing the existing role', function () {
        $membership = ProjectMembership::factory()->create();

        $this->actingAs($membership->project->creator)->postJson('/api/v1/projects/'.$membership->project_id.'/memberships', [
            'user_id' => $membership->user_id, 'access_level' => 'contributor',
        ])->assertUnprocessable()->assertJsonValidationErrors(['user_id' => 'This user is already a collaborator.']);

        $this->assertDatabaseCount('project_memberships', 1);
        $this->assertDatabaseHas('project_memberships', ['id' => $membership->id, 'access_level' => 'viewer']);
    });

    it('returns 422 for invalid membership input', function (array $payload, string $field, string $message) {
        $project = Project::factory()->create();
        $user = User::factory()->create();
        $data = array_replace(['user_id' => $user->id], $payload);

        $this->actingAs($project->creator)->postJson('/api/v1/projects/'.$project->id.'/memberships', $data)
            ->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);

        $this->assertDatabaseCount('project_memberships', 0);
    })->with([
        [['user_id' => null], 'user_id', 'The user id field is required.'],
        [['user_id' => 'invalid'], 'user_id', 'The user id field must be an integer.'],
        [['user_id' => 999999], 'user_id', 'The selected user id is invalid.'],
        [['access_level' => 'admin'], 'access_level', 'The selected access level is invalid.'],
        [['access_level' => null], 'access_level', 'The selected access level is invalid.'],
        [['project_id' => null], 'project_id', 'The project id field must be missing.'],
    ]);
});

describe('update', function () {
    it('changes only the access level in either direction', function (ProjectAccessLevel $initial, string $next) {
        $membership = ProjectMembership::factory()->create(['access_level' => $initial]);

        $this->actingAs($membership->project->creator)->patchJson('/api/v1/projects/'.$membership->project_id.'/memberships/'.$membership->id, [
            'access_level' => $next, 'id' => 999999,
        ])->assertOk()->assertJsonPath('data.access_level', $next);

        $this->assertDatabaseHas('project_memberships', [
            'id' => $membership->id, 'project_id' => $membership->project_id,
            'user_id' => $membership->user_id, 'access_level' => $next,
        ]);
    })->with([[ProjectAccessLevel::Viewer, 'contributor'], [ProjectAccessLevel::Contributor, 'viewer']]);

    it('returns 422 for invalid role changes and identity changes', function (array $payload, string $field, string $message) {
        $membership = ProjectMembership::factory()->create();

        $this->actingAs($membership->project->creator)->patchJson('/api/v1/projects/'.$membership->project_id.'/memberships/'.$membership->id, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);

        $this->assertDatabaseHas('project_memberships', [
            'id' => $membership->id, 'project_id' => $membership->project_id,
            'user_id' => $membership->user_id, 'access_level' => 'viewer',
        ]);
    })->with([
        [[], 'access_level', 'The access level field is required.'],
        [['access_level' => 'admin'], 'access_level', 'The selected access level is invalid.'],
        [['access_level' => 'contributor', 'user_id' => 999], 'user_id', 'The user id field must be missing.'],
        [['access_level' => 'contributor', 'project_id' => 999], 'project_id', 'The project id field must be missing.'],
    ]);
});

it('returns 409 for additions and role changes on archived projects', function (string $method) {
    $project = Project::factory()->archived()->create();
    $membership = ProjectMembership::factory()->for($project)->create();
    $user = User::factory()->create();
    $path = '/api/v1/projects/'.$project->id.'/memberships'.($method === 'PATCH' ? '/'.$membership->id : '');

    $this->actingAs($project->creator)->json($method, $path, ['user_id' => $user->id, 'access_level' => 'contributor'])
        ->assertConflict();

    $this->assertDatabaseCount('project_memberships', 1);
    $this->assertDatabaseHas('project_memberships', ['id' => $membership->id, 'access_level' => 'viewer']);
})->with(['POST', 'PATCH']);

it('rechecks project state after binding before adding or changing collaborators', function (string $method) {
    $project = Project::factory()->create();
    $membership = ProjectMembership::factory()->for($project)->create();
    $user = User::factory()->create();
    Route::bind('project', function (string $id): Project {
        $bound = Project::query()->findOrFail($id);
        Project::query()->findOrFail($id)->update(['status' => 'archived']);

        return $bound;
    });
    $path = '/api/v1/projects/'.$project->id.'/memberships'.($method === 'PATCH' ? '/'.$membership->id : '');
    $payload = $method === 'POST' ? ['user_id' => $user->id] : ['access_level' => 'contributor'];

    $this->actingAs($project->creator)->json($method, $path, $payload)->assertConflict();

    $this->assertDatabaseCount('project_memberships', 1);
    $this->assertDatabaseHas('project_memberships', ['id' => $membership->id, 'access_level' => 'viewer']);
})->with(['POST', 'PATCH']);

it('revokes archived project access immediately without deleting the user or unrelated memberships', function () {
    $project = Project::factory()->archived()->create();
    $membership = ProjectMembership::factory()->for($project)->create();
    $user = $membership->user;
    $otherMembership = ProjectMembership::factory()->for($user)->create();
    $token = $user->createToken('existing-client')->plainTextToken;

    $this->actingAs($project->creator)->deleteJson('/api/v1/projects/'.$project->id.'/memberships/'.$membership->id)
        ->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');

    $this->assertModelMissing($membership);
    $this->assertModelExists($user);
    $this->assertModelExists($otherMembership);
    $this->assertModelExists($project);

    auth()->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/projects/'.$project->id)->assertNotFound();
    $this->withToken($token)->getJson('/api/v1/projects')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $otherMembership->project_id);
});
