<?php

use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('stores an environment before provisioning with inactive defaults', function () {
    $project = Project::factory()->create();

    $id = DB::table('project_environments')->insertGetId([
        'project_id' => $project->id,
        'name' => 'Assessment',
        'base_image' => 'registry.example.test/tools@sha256:'.str_repeat('a', 64),
    ]);

    $this->assertDatabaseHas('project_environments', [
        'id' => $id,
        'desired_state' => 'stopped',
        'status' => 'inactive',
        'runtime_generation' => 0,
        'description' => null,
        'runtime_reference' => null,
        'runtime_status' => null,
        'workspace_reference' => null,
        'last_error' => null,
        'last_observed_at' => null,
        'network_configuration' => null,
        'resource_limits' => null,
    ]);
});

it('allows multiple environments and the same name in different projects', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();

    DB::table('project_environments')->insert([
        ['project_id' => $project->id, 'name' => 'Primary', 'base_image' => 'tools:latest'],
        ['project_id' => $project->id, 'name' => 'Secondary', 'base_image' => 'tools:latest'],
        ['project_id' => $otherProject->id, 'name' => 'Primary', 'base_image' => 'tools:latest'],
    ]);

    $this->assertDatabaseCount('project_environments', 3);
});

it('rejects duplicate environment names within a project', function () {
    $environment = ['project_id' => Project::factory()->create()->id, 'name' => 'Primary', 'base_image' => 'tools:latest'];
    DB::table('project_environments')->insert($environment);

    expect(fn () => DB::table('project_environments')->insert($environment))->toThrow(QueryException::class);
});

it('rejects environments without an existing project', function () {
    expect(fn () => DB::table('project_environments')->insert([
        'project_id' => 999,
        'name' => 'Primary',
        'base_image' => 'tools:latest',
    ]))->toThrow(QueryException::class);
});

it('blocks project deletion while environments require explicit cleanup', function () {
    $project = Project::factory()->create();
    DB::table('project_environments')->insert([
        'project_id' => $project->id, 'name' => 'Primary', 'base_image' => 'tools:latest',
    ]);

    expect(fn () => DB::table('projects')->where('id', $project->id)->delete())->toThrow(QueryException::class);
});

it('rejects invalid persisted environment states', function (string $field) {
    $project = Project::factory()->create();

    expect(fn () => DB::table('project_environments')->insert([
        'project_id' => $project->id,
        'name' => 'Primary',
        'base_image' => 'tools:latest',
        $field => 'invalid',
    ]))->toThrow(QueryException::class);
})->with(['desired_state', 'status']);

it('stores runtime state independently from readiness and workspace configuration', function () {
    $project = Project::factory()->create();

    $id = DB::table('project_environments')->insertGetId([
        'project_id' => $project->id,
        'name' => 'Primary',
        'base_image' => 'tools:latest',
        'desired_state' => 'running',
        'status' => 'starting',
        'runtime_reference' => 'container-2',
        'runtime_generation' => 2,
        'runtime_status' => 'running',
        'workspace_reference' => 'workspace-1',
        'network_configuration' => json_encode(['mode' => 'automatic'], JSON_THROW_ON_ERROR),
        'resource_limits' => json_encode(['memory_bytes' => 536870912], JSON_THROW_ON_ERROR),
    ]);

    $this->assertDatabaseHas('project_environments', [
        'id' => $id,
        'runtime_reference' => 'container-2',
        'runtime_generation' => 2,
        'workspace_reference' => 'workspace-1',
        'desired_state' => 'running',
        'status' => 'starting',
        'runtime_status' => 'running',
    ]);
    $environment = DB::table('project_environments')->find($id);
    expect(json_decode($environment->network_configuration, true))->toBe(['mode' => 'automatic']);
    expect(json_decode($environment->resource_limits, true))->toBe(['memory_bytes' => 536870912]);
});
