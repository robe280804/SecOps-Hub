<?php

use App\Enums\ProjectAccessLevel;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(LazilyRefreshDatabase::class);

it('returns 401 for project endpoints without authentication', function (string $method, string $path) {
    $project = Project::factory()->create();
    $before = $project->fresh()->getRawOriginal();

    $this->json($method, str_replace('{id}', (string) $project->id, $path), [])
        ->assertUnauthorized();

    expect($project->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('projects', 1);
})->with([
    ['GET', '/api/v1/projects'],
    ['POST', '/api/v1/projects'],
    ['GET', '/api/v1/projects/{id}'],
    ['PATCH', '/api/v1/projects/{id}'],
    ['PUT', '/api/v1/projects/{id}'],
    ['DELETE', '/api/v1/projects/{id}'],
]);

describe('index and show', function () {
    it('lists only owned and joined projects including archived projects', function () {
        $user = User::factory()->create();
        $owned = Project::factory()->for($user, 'creator')->create(['name' => 'A owned']);
        $joined = ProjectMembership::factory()->for($user)->for(
            Project::factory()->archived()->create(['name' => 'B joined'])
        )->create();
        Project::factory()->create(['name' => 'C private']);

        $this->actingAs($user)->getJson('/api/v1/projects')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.0.id', $owned->id)
            ->assertJsonPath('data.1.id', $joined->project_id)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure(['links', 'meta']);
    });

    it('paginates projects with deterministic ordering for duplicate names', function () {
        $user = User::factory()->create();
        $projects = Project::factory()->count(16)->for($user, 'creator')->create(['name' => 'Assessment']);

        $this->actingAs($user)->getJson('/api/v1/projects?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $projects->last()->id)
            ->assertJsonPath('meta.total', 16);
    });

    it('does not include unrelated projects in an admin list', function () {
        $admin = User::factory()->admin()->create();
        Project::factory()->create();

        $this->actingAs($admin)->getJson('/api/v1/projects')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    });

    it('returns only the public project fields to its owner', function () {
        $project = Project::factory()->create();

        $response = $this->actingAs($project->creator)->getJson('/api/v1/projects/'.$project->id)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.id', $project->id);

        expect(array_keys($response->json('data')))->toBe([
            'id', 'user_id', 'name', 'description', 'type', 'status', 'created_at', 'updated_at',
        ]);
    });

    it('allows collaborators to read a project and removes access after revocation', function () {
        $membership = ProjectMembership::factory()->create();
        $user = $membership->user;
        $projectId = $membership->project_id;
        $token = $user->createToken('project-client')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/projects/'.$projectId)->assertOk();
        $membership->delete();

        $this->withToken($token)->getJson('/api/v1/projects/'.$projectId)->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/projects')->assertOk()->assertJsonCount(0, 'data');
    });
});

it('returns 404 for unrelated projects even to global admins', function (string $method, string $role) {
    $user = User::factory()->create();
    $user->syncRoles($role);
    $project = Project::factory()->create();
    $before = $project->fresh()->getRawOriginal();

    $this->actingAs($user)->json($method, '/api/v1/projects/'.$project->id, ['name' => 'Unauthorized'])
        ->assertNotFound();

    expect($project->fresh()->getRawOriginal())->toBe($before);
})->with(['GET', 'PATCH', 'DELETE'])->with(['user', 'admin']);

it('returns 404 for nonexistent projects', function (string $method) {
    $user = User::factory()->create();

    $this->actingAs($user)->json($method, '/api/v1/projects/999999', ['name' => 'Missing'])
        ->assertNotFound();

    $this->assertDatabaseCount('projects', 0);
})->with(['GET', 'PATCH', 'DELETE']);

describe('store', function () {
    it('accepts boundary lengths and duplicate project names', function () {
        $user = User::factory()->create();
        $name = str_repeat('a', 255);
        $description = str_repeat('b', 10000);
        Project::factory()->for($user, 'creator')->create(['name' => $name]);

        $response = $this->actingAs($user)->postJson('/api/v1/projects', [
            'name' => $name, 'description' => $description, 'type' => 'company',
        ])->assertCreated();

        $this->assertDatabaseHas('projects', [
            'id' => $response->json('data.id'), 'name' => $name, 'description' => $description,
        ]);
        $this->assertDatabaseCount('projects', 2);
    });

    it('creates an inactive project owned by the authenticated user', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/projects', [
            'name' => 'Assessment', 'type' => 'company', 'id' => 999999,
            'created_at' => '2000-01-01', 'memberships' => [['user_id' => $user->id]],
        ])->assertCreated()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.description', null);

        $this->assertDatabaseHas('projects', [
            'id' => $response->json('data.id'), 'user_id' => $user->id,
            'name' => 'Assessment', 'type' => 'company', 'status' => 'inactive',
        ]);
        expect($response->json('data.id'))->not->toBe(999999);
        expect($response->json('data.created_at'))->not->toStartWith('2000-01-01');
        $this->assertDatabaseCount('project_memberships', 0);
    });

    it('accepts all project categories and an explicitly active status', function (string $type) {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/projects', [
            'name' => 'Assessment', 'type' => $type, 'status' => 'active', 'description' => 'Authorized work',
        ])->assertCreated()->assertJsonPath('data.type', $type)->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('projects', [
            'id' => $response->json('data.id'), 'type' => $type, 'status' => 'active', 'description' => 'Authorized work',
        ]);
    })->with(['bug_bounty', 'personal', 'company', 'ctf']);

    it('returns 422 when required project fields are missing', function () {
        $this->actingAs(User::factory()->create())->postJson('/api/v1/projects', [])
            ->assertUnprocessable()->assertJsonValidationErrors([
                'name' => 'The name field is required.', 'type' => 'The type field is required.',
            ]);

        $this->assertDatabaseCount('projects', 0);
    });

    it('returns 422 for invalid project creation data', function (string $field, mixed $value, string $message) {
        $this->actingAs(User::factory()->create())->postJson('/api/v1/projects', array_replace([
            'name' => 'Assessment', 'type' => 'company',
        ], [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);

        $this->assertDatabaseCount('projects', 0);
    })->with([
        'blank name' => ['name', ' ', 'The name field is required.'],
        'non-text name' => ['name', 123, 'The name field must be a string.'],
        'long name' => ['name', str_repeat('a', 256), 'The name field must not be greater than 255 characters.'],
        'description type' => ['description', ['invalid'], 'The description field must be a string.'],
        'long description' => ['description', str_repeat('a', 10001), 'The description field must not be greater than 10000 characters.'],
        'unknown type' => ['type', 'unknown', 'The selected type is invalid.'],
        'unknown status' => ['status', 'unknown', 'The selected status is invalid.'],
        'archived at creation' => ['status', 'archived', 'The selected status is invalid.'],
        'null status' => ['status', null, 'The selected status is invalid.'],
        'owner spoofing' => ['user_id', 999, 'The user id field must be missing.'],
        'null owner' => ['user_id', null, 'The user id field must be missing.'],
    ]);
});

describe('update', function () {
    it('returns 409 if a project was archived after route binding', function () {
        $project = Project::factory()->active()->create();
        Route::bind('project', function (string $id): Project {
            $boundProject = Project::query()->findOrFail($id);
            Project::query()->findOrFail($id)->update(['status' => 'archived']);

            return $boundProject;
        });

        $this->actingAs($project->creator)->patchJson('/api/v1/projects/'.$project->id, ['name' => 'Stale edit'])
            ->assertConflict();

        $this->assertDatabaseHas('projects', [
            'id' => $project->id, 'name' => $project->name, 'status' => 'archived',
        ]);
    });

    it('updates project fields and clears its description without changing its owner', function (string $method) {
        $project = Project::factory()->create(['description' => 'Old description']);

        $this->actingAs($project->creator)->json($method, '/api/v1/projects/'.$project->id, [
            'name' => 'Updated', 'type' => 'personal', 'description' => null, 'status' => 'active',
            'id' => 999999, 'created_at' => '2000-01-01',
        ])->assertOk()->assertJsonPath('data.name', 'Updated')->assertJsonPath('data.description', null);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id, 'user_id' => $project->user_id, 'name' => 'Updated',
            'type' => 'personal', 'description' => null, 'status' => 'active',
        ]);
        expect($project->fresh()->created_at->equalTo($project->created_at))->toBeTrue();
    })->with(['PATCH', 'PUT']);

    it('preserves omitted fields in partial updates', function () {
        $project = Project::factory()->create();
        $before = $project->fresh()->getRawOriginal();

        $this->actingAs($project->creator)->patchJson('/api/v1/projects/'.$project->id, ['name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed');

        $this->assertDatabaseHas('projects', [
            'id' => $project->id, 'name' => 'Renamed', 'description' => $before['description'],
            'type' => $before['type'], 'status' => $before['status'],
        ]);
    });

    it('returns 422 for invalid project updates without persisting any fields', function (string $field, mixed $value, string $message) {
        $project = Project::factory()->create();
        $before = $project->fresh()->getRawOriginal();

        $this->actingAs($project->creator)->patchJson('/api/v1/projects/'.$project->id, array_replace([
            'description' => 'Must not persist',
        ], [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);

        expect($project->fresh()->getRawOriginal())->toBe($before);
    })->with([
        'blank name' => ['name', '', 'The name field is required.'],
        'name type' => ['name', 123, 'The name field must be a string.'],
        'long name' => ['name', str_repeat('a', 256), 'The name field must not be greater than 255 characters.'],
        'description type' => ['description', ['invalid'], 'The description field must be a string.'],
        'long description' => ['description', str_repeat('a', 10001), 'The description field must not be greater than 10000 characters.'],
        'null type' => ['type', null, 'The type field is required.'],
        'unknown type' => ['type', 'unknown', 'The selected type is invalid.'],
        'null status' => ['status', null, 'The status field is required.'],
        'unknown status' => ['status', 'unknown', 'The selected status is invalid.'],
        'ownership transfer' => ['user_id', 999, 'The user id field must be missing.'],
    ]);

    it('returns 409 when archived projects receive edits including bundled reactivation', function (array $payload) {
        $project = Project::factory()->archived()->create();
        $before = $project->fresh()->getRawOriginal();

        $this->actingAs($project->creator)->patchJson('/api/v1/projects/'.$project->id, $payload)
            ->assertConflict()
            ->assertJsonPath('message', 'Archived projects are read-only. Reactivate the project with a status-only update first.');

        expect($project->fresh()->getRawOriginal())->toBe($before);
    })->with([
        'metadata' => [['name' => 'Changed']],
        'reactivation with edits' => [['status' => 'active', 'name' => 'Changed']],
        'empty update' => [[]],
        'already archived' => [['status' => 'archived']],
    ]);

    it('reactivates archived projects through a status-only update', function (string $status) {
        $project = Project::factory()->archived()->create();

        $this->actingAs($project->creator)->patchJson('/api/v1/projects/'.$project->id, ['status' => $status])
            ->assertOk()->assertJsonPath('data.status', $status);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => $status, 'name' => $project->name]);
    })->with(['inactive', 'active']);

    it('allows the owner to archive through a status update', function () {
        $project = Project::factory()->active()->create();

        $this->actingAs($project->creator)->patchJson('/api/v1/projects/'.$project->id, ['status' => 'archived'])
            ->assertOk()->assertJsonPath('data.status', 'archived');

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'archived']);
    });
});

it('returns 403 when collaborators attempt project writes', function (string $method, ProjectAccessLevel $access) {
    $membership = ProjectMembership::factory()->create(['access_level' => $access]);
    $project = $membership->project;
    $before = $project->fresh()->getRawOriginal();

    $this->actingAs($membership->user)->json($method, '/api/v1/projects/'.$project->id, ['name' => 'Unauthorized'])
        ->assertForbidden();

    expect($project->fresh()->getRawOriginal())->toBe($before);
    $this->assertModelExists($membership);
})->with(['PATCH', 'DELETE'])->with(ProjectAccessLevel::cases());

describe('destroy', function () {
    it('archives projects without deleting collaborators and permits repeated deletion', function () {
        $membership = ProjectMembership::factory()->create();
        $project = $membership->project;

        $this->actingAs($project->creator)->deleteJson('/api/v1/projects/'.$project->id)
            ->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
        $this->deleteJson('/api/v1/projects/'.$project->id)->assertNoContent();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'archived']);
        $this->assertModelExists($membership);
        $this->assertModelExists($membership->user);
        $this->assertModelExists($project->creator);
    });
});
