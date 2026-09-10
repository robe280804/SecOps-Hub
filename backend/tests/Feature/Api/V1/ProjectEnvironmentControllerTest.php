<?php

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config([
        'environments.approved_images' => ['registry.example.test/tools:approved', 'registry.example.test/tools:next'],
        'environments.max_per_project' => 5,
    ]);
});

dataset('environment endpoints', [
    'list' => ['GET', false],
    'create' => ['POST', false],
    'show' => ['GET', true],
    'patch' => ['PATCH', true],
    'put' => ['PUT', true],
    'delete' => ['DELETE', true],
]);

it('returns 401 for unauthenticated environment requests', function (string $method, bool $individual) {
    $environment = ProjectEnvironment::factory()->create();
    $path = '/api/v1/projects/'.$environment->project_id.'/environments'.($individual ? '/'.$environment->id : '');

    $this->json($method, $path)->assertUnauthorized();
    $this->assertModelExists($environment);
})->with('environment endpoints');

it('returns 404 for unrelated environment requests before validating their payload', function (string $method, bool $individual) {
    $environment = ProjectEnvironment::factory()->create();
    $user = User::factory()->create();
    $path = '/api/v1/projects/'.$environment->project_id.'/environments'.($individual ? '/'.$environment->id : '');

    $this->actingAs($user)->json($method, $path, ['status' => 'ready'])
        ->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
    $this->assertModelExists($environment);
})->with('environment endpoints');

it('returns 403 when a collaborator accesses environment management', function (string $method, bool $individual) {
    $environment = ProjectEnvironment::factory()->create();
    $membership = ProjectMembership::factory()->for($environment->project)->create();
    $path = '/api/v1/projects/'.$environment->project_id.'/environments'.($individual ? '/'.$environment->id : '');

    $this->actingAs($membership->user)->json($method, $path)->assertForbidden();
    $this->assertModelExists($environment);
})->with('environment endpoints');

it('returns 404 for nested environment IDs from another project even with the same owner', function (string $method) {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->for($project->creator, 'creator')->create();
    $environment = ProjectEnvironment::factory()->for($otherProject)->create();

    $this->actingAs($project->creator)->json($method, '/api/v1/projects/'.$project->id.'/environments/'.$environment->id, ['name' => 'Changed'])
        ->assertNotFound();
    $this->assertDatabaseHas('project_environments', ['id' => $environment->id, 'name' => $environment->name]);
})->with(['GET', 'PATCH', 'PUT', 'DELETE']);

it('returns 409 for writes to archived projects', function (string $method, bool $individual) {
    $environment = ProjectEnvironment::factory()->for(Project::factory()->archived())->create();
    $path = '/api/v1/projects/'.$environment->project_id.'/environments'.($individual ? '/'.$environment->id : '');

    $this->actingAs($environment->project->creator)->json($method, $path, [
        'name' => 'Changed', 'base_image' => 'registry.example.test/tools:approved',
    ])->assertConflict()->assertJsonPath('message', 'Archived projects are read-only. Reactivate the project first.');
    $this->assertDatabaseHas('project_environments', ['id' => $environment->id, 'name' => $environment->name]);
})->with([['POST', false], ['PATCH', true], ['DELETE', true]]);

it('lists only the requested project environments with deterministic pagination in the archive', function () {
    $project = Project::factory()->archived()->create();
    $environments = ProjectEnvironment::factory()->count(16)->for($project)
        ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Environment %02d', $sequence->index)])
        ->create();
    ProjectEnvironment::factory()->create();

    $this->actingAs($project->creator)->getJson('/api/v1/projects/'.$project->id.'/environments?page=2')
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 16)
        ->assertJsonPath('data.0.id', $environments->last()->id);
});

it('returns an empty list when the project has no environments', function () {
    $project = Project::factory()->create();

    $this->actingAs($project->creator)->getJson('/api/v1/projects/'.$project->id.'/environments')
        ->assertOk()->assertJsonCount(0, 'data');
});

it('shows approved response fields without runtime references or raw errors', function () {
    $environment = ProjectEnvironment::factory()->ready()->create(['last_error' => 'Secret daemon error']);

    $response = $this->actingAs($environment->project->creator)
        ->getJson('/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.status', 'ready');

    expect(array_keys($response->json('data')))->toBe([
        'id', 'project_id', 'name', 'description', 'base_image', 'desired_state', 'status',
        'runtime_generation', 'runtime_status', 'last_observed_at',
        'network_configuration', 'resource_limits', 'capabilities', 'created_at', 'updated_at',
    ]);
    expect($response->getContent())->not->toContain('Secret daemon error', $environment->runtime_reference, $environment->workspace_reference);
});

it('creates a configuration with safe defaults without dispatching provisioning', function () {
    Queue::fake();
    $project = Project::factory()->create();

    $response = $this->actingAs($project->creator)->postJson('/api/v1/projects/'.$project->id.'/environments', [
        'name' => '  Recon  ', 'base_image' => 'registry.example.test/tools:approved',
        'unexpected' => 'ignored',
    ])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.name', 'Recon')->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.desired_state', 'stopped')->assertJsonPath('data.runtime_generation', 0)
        ->assertJsonPath('data.network_configuration.mode', 'automatic')
        ->assertJsonPath('data.resource_limits.memory_bytes', 536870912);

    $this->assertDatabaseHas('project_environments', [
        'id' => $response->json('data.id'), 'project_id' => $project->id,
        'name' => 'Recon', 'description' => null, 'runtime_reference' => null, 'workspace_reference' => null,
    ]);
    Queue::assertNothingPushed();
});

it('creates an environment with validated custom configuration', function () {
    $project = Project::factory()->active()->create();
    $network = ['version' => 1, 'mode' => 'automatic', 'dns_servers' => ['1.1.1.1', '2606:4700:4700::1111'], 'search_domains' => ['lab.example.test']];
    $limits = ['version' => 1, 'cpus' => 0.5, 'memory_bytes' => 1073741824, 'storage_bytes' => 10737418240, 'pids' => 256];

    $response = $this->actingAs($project->creator)->postJson('/api/v1/projects/'.$project->id.'/environments', [
        'name' => 'Recon', 'description' => 'Custom setup', 'base_image' => 'registry.example.test/tools:approved',
        'network_configuration' => $network, 'resource_limits' => $limits,
    ])->assertCreated()->assertJsonPath('data.network_configuration', $network)->assertJsonPath('data.resource_limits', $limits);

    $environment = ProjectEnvironment::findOrFail($response->json('data.id'));
    expect($environment->network_configuration)->toBe($network);
    expect($environment->resource_limits)->toBe($limits);
});

it('returns 422 for missing required creation fields', function () {
    $project = Project::factory()->create();

    $this->actingAs($project->creator)->postJson('/api/v1/projects/'.$project->id.'/environments', [])
        ->assertUnprocessable()->assertJsonValidationErrors([
            'name' => 'The name field is required.',
            'base_image' => 'The base image field is required.',
        ]);
    $this->assertDatabaseCount('project_environments', 0);
});

it('returns 422 for invalid configuration on creation and update', function (array $payload, string $field, string $message, string $method) {
    $environment = ProjectEnvironment::factory()->create(['base_image' => 'registry.example.test/tools:approved']);
    $path = '/api/v1/projects/'.$environment->project_id.'/environments'.($method === 'PATCH' ? '/'.$environment->id : '');
    $original = $environment->fresh()->getAttributes();
    $data = array_replace([
        'name' => 'New environment',
        'base_image' => 'registry.example.test/tools:approved',
    ], $payload);

    $this->actingAs($environment->project->creator)->json($method, $path, $data)
        ->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);

    $this->assertDatabaseCount('project_environments', 1);
    expect($environment->fresh()->getAttributes())->toBe($original);
})->with([
    'null name' => [['name' => null], 'name', 'The name field is required.'],
    'non-string name' => [['name' => 3], 'name', 'The name field must be a string.'],
    'long name' => [['name' => str_repeat('a', 256)], 'name', 'The name field must not be greater than 255 characters.'],
    'invalid description' => [['description' => []], 'description', 'The description field must be a string.'],
    'long description' => [['description' => str_repeat('a', 10001)], 'description', 'The description field must not be greater than 10000 characters.'],
    'null image' => [['base_image' => null], 'base_image', 'The base image field is required.'],
    'unapproved image' => [['base_image' => 'untrusted:latest'], 'base_image', 'The selected base image is not approved by the platform.'],
    'null network' => [['network_configuration' => null], 'network_configuration', 'The network configuration field is required.'],
    'incomplete network' => [['network_configuration' => ['version' => 1, 'mode' => 'automatic']], 'network_configuration', 'The network configuration must include version, mode, dns_servers and search_domains.'],
    'non-array network' => [['network_configuration' => 'automatic'], 'network_configuration', 'The network configuration field must be an array.'],
    'network extra keys' => [['network_configuration' => ['version' => 1, 'mode' => 'automatic', 'dns_servers' => [], 'search_domains' => [], 'password' => 'secret']], 'network_configuration', 'The network configuration field must be an array.'],
    'unsupported network mode' => [['network_configuration' => ['version' => 1, 'mode' => 'static', 'dns_servers' => [], 'search_domains' => []]], 'network_configuration.mode', 'Only automatic network addressing is supported.'],
    'unknown network version' => [['network_configuration' => ['version' => 2, 'mode' => 'automatic', 'dns_servers' => [], 'search_domains' => []]], 'network_configuration.version', 'The selected network configuration version is invalid.'],
    'dns hostname' => [['network_configuration' => ['version' => 1, 'mode' => 'automatic', 'dns_servers' => ['resolver.test'], 'search_domains' => []]], 'network_configuration.dns_servers.0', 'The DNS server field must be a valid IP address.'],
    'dns object' => [['network_configuration' => ['version' => 1, 'mode' => 'automatic', 'dns_servers' => ['resolver' => '1.1.1.1'], 'search_domains' => []]], 'network_configuration.dns_servers', 'The DNS servers field must be a list.'],
    'invalid search domain' => [['network_configuration' => ['version' => 1, 'mode' => 'automatic', 'dns_servers' => [], 'search_domains' => ['https://example.test']]], 'network_configuration.search_domains.0', 'The search domain field format is invalid.'],
    'null limits' => [['resource_limits' => null], 'resource_limits', 'The resource limits field is required.'],
    'incomplete limits' => [['resource_limits' => ['cpus' => 1]], 'resource_limits', 'The resource limits must include version, cpus, memory_bytes, storage_bytes and pids.'],
    'unknown resource version' => [['resource_limits' => ['version' => 2, 'cpus' => 1, 'memory_bytes' => 536870912, 'storage_bytes' => 5368709120, 'pids' => 128]], 'resource_limits.version', 'The selected resource configuration version is invalid.'],
    'string cpu' => [['resource_limits' => ['version' => 1, 'cpus' => '1', 'memory_bytes' => 536870912, 'storage_bytes' => 5368709120, 'pids' => 128]], 'resource_limits.cpus', 'The CPU limit field must be a number.'],
    'insufficient memory' => [['resource_limits' => ['version' => 1, 'cpus' => 1, 'memory_bytes' => 0, 'storage_bytes' => 5368709120, 'pids' => 128]], 'resource_limits.memory_bytes', 'The memory limit field must be at least 67108864.'],
    'insufficient storage' => [['resource_limits' => ['version' => 1, 'cpus' => 1, 'memory_bytes' => 536870912, 'storage_bytes' => 0, 'pids' => 128]], 'resource_limits.storage_bytes', 'The storage limit field must be at least 1073741824.'],
    'unlimited processes' => [['resource_limits' => ['version' => 1, 'cpus' => 1, 'memory_bytes' => 536870912, 'storage_bytes' => 5368709120, 'pids' => 0]], 'resource_limits.pids', 'The process limit field must be at least 16.'],
    'limits extra keys' => [['resource_limits' => ['version' => 1, 'cpus' => 1, 'memory_bytes' => 536870912, 'storage_bytes' => 5368709120, 'pids' => 128, 'privileged' => true]], 'resource_limits', 'The resource limits field must be an array.'],
    'unlimited cpu' => [['resource_limits' => ['version' => 1, 'cpus' => 0, 'memory_bytes' => 536870912, 'storage_bytes' => 5368709120, 'pids' => 128]], 'resource_limits.cpus', 'The CPU limit field must be at least 0.1.'],
    'excessive cpu' => [['resource_limits' => ['version' => 1, 'cpus' => 5, 'memory_bytes' => 536870912, 'storage_bytes' => 5368709120, 'pids' => 128]], 'resource_limits.cpus', 'The CPU limit field must not be greater than 4.'],
    'excessive memory' => [['resource_limits' => ['version' => 1, 'cpus' => 1, 'memory_bytes' => 8589934593, 'storage_bytes' => 5368709120, 'pids' => 128]], 'resource_limits.memory_bytes', 'The memory limit field must not be greater than 8589934592.'],
    'excessive storage' => [['resource_limits' => ['version' => 1, 'cpus' => 1, 'memory_bytes' => 536870912, 'storage_bytes' => 53687091201, 'pids' => 128]], 'resource_limits.storage_bytes', 'The storage limit field must not be greater than 53687091200.'],
    'excessive pids' => [['resource_limits' => ['version' => 1, 'cpus' => 1, 'memory_bytes' => 536870912, 'storage_bytes' => 5368709120, 'pids' => 513]], 'resource_limits.pids', 'The process limit field must not be greater than 512.'],
    'noninteger pids' => [['resource_limits' => ['version' => 1, 'cpus' => 1, 'memory_bytes' => 536870912, 'storage_bytes' => 5368709120, 'pids' => 128.5]], 'resource_limits.pids', 'The process limit field must be an integer.'],
])->with(['POST', 'PATCH']);

it('rejects client-controlled identity and runtime fields even when null', function (string $field, string $method) {
    $environment = ProjectEnvironment::factory()->create();
    $path = '/api/v1/projects/'.$environment->project_id.'/environments'.($method === 'PATCH' ? '/'.$environment->id : '');

    $this->actingAs($environment->project->creator)->json($method, $path, [
        'name' => 'New', 'base_image' => 'registry.example.test/tools:approved', $field => null,
    ])->assertUnprocessable()->assertJsonValidationErrors([$field => 'The '.str_replace('_', ' ', $field).' field must be missing.']);

    $this->assertDatabaseCount('project_environments', 1);
})->with([
    'id', 'project_id', 'user_id', 'desired_state', 'status', 'runtime_reference', 'runtime_generation',
    'runtime_status', 'workspace_reference', 'last_error', 'last_observed_at', 'created_at', 'updated_at',
])->with(['POST', 'PATCH']);

it('rejects creation when no images have been approved', function () {
    config(['environments.approved_images' => []]);
    $project = Project::factory()->create();

    $this->actingAs($project->creator)->postJson('/api/v1/projects/'.$project->id.'/environments', [
        'name' => 'Recon', 'base_image' => 'registry.example.test/tools:approved',
    ])->assertUnprocessable()->assertJsonValidationErrors(['base_image' => 'The selected base image is not approved by the platform.']);
    $this->assertDatabaseCount('project_environments', 0);
});

it('enforces the quota including provisioning and deleting environments', function () {
    config(['environments.max_per_project' => 2]);
    $project = Project::factory()->create();
    ProjectEnvironment::factory()->for($project)->provisioning()->create();
    ProjectEnvironment::factory()->for($project)->deleting()->create();

    $this->actingAs($project->creator)->postJson('/api/v1/projects/'.$project->id.'/environments', [
        'name' => 'Recon', 'base_image' => 'registry.example.test/tools:approved',
    ])->assertConflict()->assertJsonPath('message', 'The environment limit for this project has been reached.');
    $this->assertDatabaseCount('project_environments', 2);
});

it('isolates quota and name uniqueness by project', function () {
    config(['environments.max_per_project' => 1]);
    $environment = ProjectEnvironment::factory()->create(['name' => 'Recon']);
    $project = Project::factory()->for($environment->project->creator, 'creator')->create();

    $this->actingAs($project->creator)->postJson('/api/v1/projects/'.$project->id.'/environments', [
        'name' => 'Recon', 'base_image' => 'registry.example.test/tools:approved',
    ])->assertCreated();
    $this->assertDatabaseCount('project_environments', 2);
});

it('returns 422 for a duplicate name on creation or rename', function (string $method) {
    $environment = ProjectEnvironment::factory()->create(['name' => 'Existing']);
    $other = ProjectEnvironment::factory()->for($environment->project)->create(['name' => 'Other']);
    $path = '/api/v1/projects/'.$environment->project_id.'/environments'.($method === 'PATCH' ? '/'.$other->id : '');

    $this->actingAs($environment->project->creator)->json($method, $path, [
        'name' => 'Existing', 'base_image' => 'registry.example.test/tools:approved',
    ])->assertUnprocessable()->assertJsonValidationErrors(['name' => 'An environment with this name already exists in this project.']);
    $this->assertDatabaseCount('project_environments', 2);
    expect($other->fresh()->name)->toBe('Other');
})->with(['POST', 'PATCH']);

it('updates only supplied fields and allows keeping the same name', function (string $method) {
    $environment = ProjectEnvironment::factory()->create(['base_image' => 'registry.example.test/tools:approved']);

    $this->actingAs($environment->project->creator)->json($method, '/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id, [
        'name' => $environment->name, 'description' => null,
    ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.description', null);

    $fresh = $environment->fresh();
    expect($fresh->description)->toBeNull();
    expect($fresh->base_image)->toBe($environment->base_image);
    expect($fresh->resource_limits)->toBe($environment->resource_limits);
    expect($fresh->network_configuration)->toBe($environment->network_configuration);
})->with(['PATCH', 'PUT']);

it('replaces complete configuration before provisioning', function () {
    $environment = ProjectEnvironment::factory()->create();
    $network = ['version' => 1, 'mode' => 'automatic', 'dns_servers' => ['8.8.8.8'], 'search_domains' => []];

    $this->actingAs($environment->project->creator)->patchJson('/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id, [
        'base_image' => 'registry.example.test/tools:next', 'network_configuration' => $network,
    ])->assertOk();

    expect($environment->fresh()->base_image)->toBe('registry.example.test/tools:next');
    expect($environment->fresh()->network_configuration)->toBe($network);
});

it('allows descriptive edits after provisioning without changing runtime configuration', function () {
    $environment = ProjectEnvironment::factory()->ready()->create();

    $this->actingAs($environment->project->creator)->patchJson('/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id, [
        'description' => 'Updated notes',
    ])->assertOk();

    $this->assertDatabaseHas('project_environments', [
        'id' => $environment->id, 'description' => 'Updated notes',
        'runtime_reference' => $environment->runtime_reference, 'status' => 'ready',
    ]);
});

it('returns 409 for runtime configuration changes after provisioning and rolls back metadata', function (string $field, mixed $value) {
    $environment = ProjectEnvironment::factory()->stopped()->create();

    $this->actingAs($environment->project->creator)->patchJson('/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id, [
        'name' => 'Changed', $field => $value,
    ])->assertConflict()->assertJsonPath('message', 'Runtime configuration can only be changed before provisioning.');

    $this->assertDatabaseHas('project_environments', ['id' => $environment->id, 'name' => $environment->name]);
})->with([
    ['base_image', 'registry.example.test/tools:next'],
    ['network_configuration', ['version' => 1, 'mode' => 'automatic', 'dns_servers' => ['1.1.1.1'], 'search_domains' => []]],
    ['resource_limits', ['version' => 1, 'cpus' => 2, 'memory_bytes' => 536870912, 'storage_bytes' => 5368709120, 'pids' => 128]],
]);

it('returns 409 when editing during provisioning', function () {
    $environment = ProjectEnvironment::factory()->provisioning()->create();

    $this->actingAs($environment->project->creator)->patchJson('/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id, ['name' => 'Changed'])
        ->assertConflict()->assertJsonPath('message', 'Wait for the environment operation to finish before editing.');
    expect($environment->fresh()->name)->toBe($environment->name);
});

it('deletes an unprovisioned environment and frees its quota without deleting the project', function () {
    config(['environments.max_per_project' => 1]);
    $environment = ProjectEnvironment::factory()->create(['name' => 'Recon']);
    $project = $environment->project;

    $this->actingAs($project->creator)->deleteJson('/api/v1/projects/'.$project->id.'/environments/'.$environment->id)
        ->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');

    $this->assertModelMissing($environment);
    $this->assertModelExists($project);
    $this->postJson('/api/v1/projects/'.$project->id.'/environments', [
        'name' => 'Recon', 'base_image' => 'registry.example.test/tools:approved',
    ])->assertCreated();
});

it('returns 409 when deletion would discard a provisioned workspace', function () {
    $environment = ProjectEnvironment::factory()->stopped()->create();

    $this->actingAs($environment->project->creator)->deleteJson('/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id)
        ->assertConflict()->assertJsonPath('message', 'Provisioned environments require runtime cleanup before deletion.');
    $this->assertModelExists($environment);
});

it('rechecks project archival inside the write transaction', function (string $method, bool $individual) {
    $environment = ProjectEnvironment::factory()->create();
    $project = $environment->project;
    $owner = $project->creator;
    $changed = false;
    $initialTransactionLevel = DB::transactionLevel();
    DB::connection()->beforeExecuting(function (string $query) use ($project, &$changed, $initialTransactionLevel): void {
        if (! $changed && DB::transactionLevel() > $initialTransactionLevel && str_starts_with($query, 'select')) {
            $changed = true;
            DB::table('projects')->where('id', $project->id)->update(['status' => ProjectStatus::Archived->value]);
        }
    });
    $path = '/api/v1/projects/'.$project->id.'/environments'.($individual ? '/'.$environment->id : '');

    $this->actingAs($owner)->json($method, $path, [
        'name' => 'Changed', 'base_image' => 'registry.example.test/tools:approved',
    ])->assertConflict();

    expect($changed)->toBeTrue();
    $this->assertDatabaseHas('project_environments', ['id' => $environment->id, 'name' => $environment->name]);
})->with([['POST', false], ['PATCH', true], ['DELETE', true]]);

it('protects every environment endpoint with the existing authentication and API throttle', function () {
    foreach (['index', 'store', 'show', 'update', 'destroy'] as $action) {
        $route = Route::getRoutes()->getByName('api.v1.projects.environments.'.$action);

        expect($route->gatherMiddleware())->toContain('auth:sanctum', 'throttle:api');
    }
});
