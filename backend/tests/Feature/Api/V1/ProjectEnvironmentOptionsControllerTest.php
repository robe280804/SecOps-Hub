<?php

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('provides only the current platform settings needed by the environment form', function () {
    $project = Project::factory()->create();
    config(['environments.approved_images' => ['tools:approved'], 'environments.max_per_project' => 7]);

    $response = $this->actingAs($project->creator)->getJson('/api/v1/projects/'.$project->id.'/environment-options')
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.approved_images', ['tools:approved'])
        ->assertJsonPath('data.max_per_project', 7)
        ->assertJsonPath('data.min_resource_limits.memory_bytes', 67108864)
        ->assertJsonPath('data.can_create', true);

    expect(array_keys($response->json('data')))->toBe([
        'approved_images', 'max_per_project', 'default_network_configuration', 'default_resource_limits',
        'min_resource_limits', 'max_resource_limits', 'network_limits', 'can_create',
    ]);
});

it('returns 401 when requesting options without authentication', function () {
    $project = Project::factory()->create();

    $this->getJson('/api/v1/projects/'.$project->id.'/environment-options')->assertUnauthorized();
});

it('returns 403 to collaborators requesting environment options', function () {
    $membership = ProjectMembership::factory()->create();

    $this->actingAs($membership->user)->getJson('/api/v1/projects/'.$membership->project_id.'/environment-options')->assertForbidden();
});

it('returns 404 to unrelated users and global admins requesting options', function (string $role) {
    $user = User::factory()->create();
    $user->syncRoles($role);
    $project = Project::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/projects/'.$project->id.'/environment-options')->assertNotFound();
})->with(['user', 'admin']);

it('allows archived project owners to inspect options but disables creation', function () {
    $project = Project::factory()->archived()->create();

    $this->actingAs($project->creator)->getJson('/api/v1/projects/'.$project->id.'/environment-options')
        ->assertOk()->assertJsonPath('data.can_create', false);
});

it('exposes capabilities without revealing persistent runtime references', function (string $state, bool $configure, bool $update, bool $delete) {
    $environment = ProjectEnvironment::factory()->{$state}()->create();

    $this->actingAs($environment->project->creator)->getJson('/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id)
        ->assertOk()->assertJsonPath('data.capabilities', ['update' => $update, 'configure' => $configure, 'delete' => $delete])
        ->assertJsonMissingPath('data.workspace_reference')->assertJsonMissingPath('data.runtime_reference');
})->with([
    ['ready', false, true, false],
    ['stopped', false, true, false],
    ['provisioning', false, false, false],
    ['deleting', false, false, false],
]);

it('returns no writable capabilities for archived environments', function () {
    $environment = ProjectEnvironment::factory()->for(Project::factory()->archived())->create();

    $this->actingAs($environment->project->creator)->getJson('/api/v1/projects/'.$environment->project_id.'/environments')
        ->assertOk()->assertJsonPath('data.0.capabilities', ['update' => false, 'configure' => false, 'delete' => false]);
});

it('allows configuration of inactive environments but prevents deletion when a workspace exists', function () {
    $environment = ProjectEnvironment::factory()->create();
    $owner = $environment->project->creator;
    $path = '/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id;
    $this->actingAs($owner)->getJson($path)->assertOk()
        ->assertJsonPath('data.capabilities', ['update' => true, 'configure' => true, 'delete' => true]);

    $environment->workspace_reference = 'internal-volume';
    $environment->save();

    $this->getJson($path)->assertOk()
        ->assertJsonPath('data.capabilities', ['update' => true, 'configure' => false, 'delete' => false]);
});
