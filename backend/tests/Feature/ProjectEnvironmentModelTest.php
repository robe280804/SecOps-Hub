<?php

use App\Enums\EnvironmentDesiredState;
use App\Enums\EnvironmentStatus;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(LazilyRefreshDatabase::class);

it('creates an unprovisioned environment through its project with model defaults', function () {
    $project = Project::factory()->create();

    $environment = $project->environments()->create(['name' => 'Recon', 'base_image' => 'tools:latest']);

    expect($environment->project->is($project))->toBeTrue();
    expect($project->environments()->sole()->is($environment))->toBeTrue();
    expect($environment->desired_state)->toBe(EnvironmentDesiredState::Stopped);
    expect($environment->status)->toBe(EnvironmentStatus::Inactive);
    expect($environment->runtime_generation)->toBe(0);
    expect($environment->isUnprovisioned())->toBeTrue();
});

it('casts persisted desired states', function (EnvironmentDesiredState $state) {
    $environment = ProjectEnvironment::factory()->create(['desired_state' => $state]);

    expect($environment->fresh()->desired_state)->toBe($state);
})->with(EnvironmentDesiredState::cases());

it('casts persisted application states', function (EnvironmentStatus $status) {
    $environment = ProjectEnvironment::factory()->create(['status' => $status]);

    expect($environment->fresh()->status)->toBe($status);
})->with(EnvironmentStatus::cases());

it('casts configuration and observation times and hides internal details', function () {
    $this->travelTo(now()->startOfSecond());
    $environment = ProjectEnvironment::factory()->ready()->create(['last_error' => 'Internal daemon details']);

    $fresh = $environment->fresh();

    expect($fresh->network_configuration)->toBe(['version' => 1, 'mode' => 'automatic', 'dns_servers' => [], 'search_domains' => []]);
    expect($fresh->resource_limits)->toBe(['version' => 1, 'cpus' => 1, 'memory_bytes' => 536870912, 'storage_bytes' => 5368709120, 'pids' => 128]);
    expect($fresh->last_observed_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($fresh->last_observed_at->equalTo(now()))->toBeTrue();
    expect($fresh->toArray())->not->toHaveKeys(['runtime_reference', 'workspace_reference', 'last_error']);
});

it('guards environment identity and runtime fields against mass assignment', function () {
    $environment = ProjectEnvironment::factory()->create();
    $original = $environment->getAttributes();

    $environment->fill([
        'id' => 999999,
        'project_id' => 999999,
        'desired_state' => 'running',
        'status' => 'ready',
        'runtime_reference' => 'container',
        'runtime_generation' => 9,
        'runtime_status' => 'running',
        'workspace_reference' => 'volume',
        'last_error' => 'injected',
        'last_observed_at' => '2000-01-01',
        'created_at' => '2000-01-01',
        'updated_at' => '2000-01-01',
    ]);

    expect($environment->getAttributes())->toBe($original);
});

it('rejects moving an environment to another project', function () {
    $environment = ProjectEnvironment::factory()->create();
    $originalProjectId = $environment->project_id;
    $environment->project()->associate(Project::factory()->create());

    expect(fn () => $environment->save())->toThrow(ValidationException::class, 'An environment cannot be moved to another project.');

    $this->assertDatabaseHas('project_environments', ['id' => $environment->id, 'project_id' => $originalProjectId]);
});

it('provides coherent runtime factory states', function (string $state, EnvironmentDesiredState $desired, EnvironmentStatus $status, ?string $runtimeStatus) {
    $environment = ProjectEnvironment::factory()->{$state}()->create();

    expect($environment->desired_state)->toBe($desired);
    expect($environment->status)->toBe($status);
    expect($environment->runtime_status)->toBe($runtimeStatus);
    expect($environment->isUnprovisioned())->toBeFalse();
})->with([
    ['provisioning', EnvironmentDesiredState::Running, EnvironmentStatus::Provisioning, null],
    ['ready', EnvironmentDesiredState::Running, EnvironmentStatus::Ready, 'running'],
    ['stopped', EnvironmentDesiredState::Stopped, EnvironmentStatus::Stopped, 'exited'],
    ['deleting', EnvironmentDesiredState::Deleted, EnvironmentStatus::Deleting, 'exited'],
]);
