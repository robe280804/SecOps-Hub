<?php

use App\Jobs\StartProjectEnvironment;
use App\Models\EnvironmentOperation;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\EnvironmentProvisioner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config([
        'environments.runtime.enabled' => true,
        'environments.runtime.allow_unlimited_storage' => true,
        'environments.runtime.namespace' => 'secops-test',
        'environments.approved_images' => ['tools:approved'],
    ]);
});

function pendingEnvironmentStart(): EnvironmentOperation
{
    $environment = ProjectEnvironment::factory()->provisioning()->for(Project::factory()->active())->create(['base_image' => 'tools:approved']);

    return EnvironmentOperation::factory()->for($environment, 'environment')->create(['requested_by' => $environment->project->user_id]);
}

/** @return object{volumes: array, networks: array, containers: array, starts: int} */
function fakeEnvironmentDocker(bool $failFirstStart = false): object
{
    $state = (object) ['volumes' => [], 'networks' => [], 'containers' => [], 'starts' => 0];
    Http::preventStrayRequests();
    Http::fake(['http://localhost/*' => function (Request $request) use ($state, $failFirstStart) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/version') {
            return Http::response(['ApiVersion' => '1.54', 'MinAPIVersion' => '1.40']);
        }
        $path = substr($path, strlen('/v1.54'));
        if ($request->method() === 'GET') {
            foreach (['volumes', 'networks'] as $kind) {
                if (str_starts_with($path, '/'.$kind.'/')) {
                    $name = substr($path, strlen($kind) + 2);

                    return isset($state->{$kind}[$name]) ? Http::response($state->{$kind}[$name]) : Http::response([], 404);
                }
            }
            if (preg_match('#^/containers/([^/]+)/json$#', $path, $matches)) {
                foreach ($state->containers as $name => $container) {
                    if ($matches[1] === $name || $matches[1] === $container['Id']) {
                        return Http::response($container);
                    }
                }

                return Http::response([], 404);
            }
        }
        if ($request->method() === 'POST') {
            if ($path === '/volumes/create' || $path === '/networks/create') {
                $kind = $path === '/volumes/create' ? 'volumes' : 'networks';
                $state->{$kind}[$request['Name']] = $request->data();

                return Http::response($request->data(), 201);
            }
            if ($path === '/containers/create') {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $state->containers[$query['name']] = ['Id' => str_repeat('a', 64), 'Config' => $request->data(), 'State' => ['Running' => false]];

                return Http::response(['Id' => str_repeat('a', 64)], 201);
            }
            if ($path === '/containers/'.str_repeat('a', 64).'/start') {
                $state->starts++;
                foreach ($state->containers as &$container) {
                    $container['State']['Running'] = true;
                }
                if ($failFirstStart && $state->starts === 1) {
                    return Http::response([], 503);
                }

                return Http::response(null, 204);
            }
        }
        throw new RuntimeException('Unexpected Docker request: '.$request->method().' '.$path);
    }]);

    return $state;
}

it('starts a constrained container with a persistent workspace without claiming terminal readiness', function () {
    $operation = pendingEnvironmentStart();
    $state = fakeEnvironmentDocker();

    (new StartProjectEnvironment($operation->id))->handle(app(EnvironmentProvisioner::class));

    $this->assertDatabaseHas('environment_operations', ['id' => $operation->id, 'status' => 'completed', 'attempts' => 1]);
    $this->assertDatabaseHas('project_environments', ['id' => $operation->project_environment_id, 'status' => 'running', 'runtime_status' => 'running', 'runtime_generation' => 1]);
    expect($state->volumes)->toHaveCount(1);
    expect($state->networks)->toHaveCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/containers/create?')
        && $request['HostConfig']['Memory'] === 536870912
        && $request['HostConfig']['NanoCpus'] === 1000000000
        && $request['HostConfig']['PidsLimit'] === 128
        && $request['HostConfig']['Privileged'] === false
        && $request['HostConfig']['Mounts'][0]['Target'] === '/workspace'
        && $request['HostConfig']['CapDrop'] === ['NET_RAW', 'NET_ADMIN']);
});

it('recovers an uncertain start without duplicating resources or starting the container again', function () {
    $operation = pendingEnvironmentStart();
    $state = fakeEnvironmentDocker(true);
    $job = new StartProjectEnvironment($operation->id);

    expect(fn () => $job->handle(app(EnvironmentProvisioner::class)))->toThrow(RuntimeException::class);
    $job->handle(app(EnvironmentProvisioner::class));
    $job->handle(app(EnvironmentProvisioner::class));

    expect($state->containers)->toHaveCount(1);
    expect($state->volumes)->toHaveCount(1);
    expect($state->starts)->toBe(1);
    $this->assertDatabaseHas('environment_operations', ['id' => $operation->id, 'status' => 'completed', 'attempts' => 2]);
    Http::assertSentCount(15);
});

it('cancels queued startup when the project is no longer active', function () {
    $operation = pendingEnvironmentStart();
    $operation->environment->project->update(['status' => 'archived']);
    Http::preventStrayRequests();
    Http::fake();

    (new StartProjectEnvironment($operation->id))->handle(app(EnvironmentProvisioner::class));

    $this->assertDatabaseHas('environment_operations', ['id' => $operation->id, 'status' => 'cancelled']);
    Http::assertNothingSent();
});

it('refuses resources whose ownership labels conflict', function () {
    $operation = pendingEnvironmentStart();
    $state = fakeEnvironmentDocker();
    $environment = $operation->environment;
    $state->volumes['secops-test-project-'.$environment->project_id.'-env-'.$environment->id.'-workspace'] = ['Labels' => ['secops.environment' => '999']];

    expect(fn () => (new StartProjectEnvironment($operation->id))->handle(app(EnvironmentProvisioner::class)))
        ->toThrow(RuntimeException::class, 'A Docker resource has conflicting ownership labels.');

    expect($state->containers)->toBeEmpty();
    Http::assertSentCount(2);
});

it('requires explicit storage quota configuration before contacting Docker', function () {
    $operation = pendingEnvironmentStart();
    config(['environments.runtime.allow_unlimited_storage' => false]);
    Http::preventStrayRequests();
    Http::fake();

    expect(fn () => (new StartProjectEnvironment($operation->id))->handle(app(EnvironmentProvisioner::class)))
        ->toThrow(RuntimeException::class, 'A volume driver with workspace quota support must be configured.');

    Http::assertNothingSent();
});

it('preserves runtime references and redacts terminal failure details', function () {
    $operation = pendingEnvironmentStart();
    $operation->environment->forceFill(['workspace_reference' => 'workspace', 'runtime_reference' => 'container'])->save();

    (new StartProjectEnvironment($operation->id))->failed(new RuntimeException('secret daemon credentials'));

    $this->assertDatabaseHas('project_environments', ['id' => $operation->project_environment_id, 'status' => 'error', 'workspace_reference' => 'workspace', 'runtime_reference' => 'container']);
    $this->assertDatabaseHas('environment_operations', ['id' => $operation->id, 'status' => 'failed']);
    expect($operation->environment->refresh()->last_error)->not->toContain('secret');
});

it('recovers an operation left pending before queue dispatch', function () {
    $operation = pendingEnvironmentStart();
    $operation->forceFill(['updated_at' => now()->subMinutes(6)])->save();
    Queue::fake([StartProjectEnvironment::class]);

    $this->artisan('environments:recover')->assertSuccessful();

    Queue::assertPushed(StartProjectEnvironment::class, fn (StartProjectEnvironment $job): bool => $job->operationId === $operation->id);
});

it('does not replace a missing persistent workspace on retry', function () {
    $operation = pendingEnvironmentStart();
    $environment = $operation->environment;
    $environment->forceFill(['workspace_reference' => 'secops-test-project-'.$environment->project_id.'-env-'.$environment->id.'-workspace'])->save();
    $state = fakeEnvironmentDocker();

    expect(fn () => (new StartProjectEnvironment($operation->id))->handle(app(EnvironmentProvisioner::class)))
        ->toThrow(RuntimeException::class, 'The persistent workspace is missing; automatic replacement is disabled.');

    expect($state->volumes)->toBeEmpty();
    Http::assertSentCount(2);
});

it('restarts the existing stopped container and retains its generation and workspace', function () {
    $operation = pendingEnvironmentStart();
    $state = fakeEnvironmentDocker();
    $job = new StartProjectEnvironment($operation->id);
    $job->handle(app(EnvironmentProvisioner::class));
    foreach ($state->containers as &$container) {
        $container['State']['Running'] = false;
    }
    $environment = $operation->environment->refresh();
    $originalWorkspace = $environment->workspace_reference;
    $environment->forceFill(['status' => 'starting', 'runtime_status' => 'exited'])->save();
    $restart = EnvironmentOperation::factory()->for($environment, 'environment')->create(['requested_by' => $operation->requested_by]);

    (new StartProjectEnvironment($restart->id))->handle(app(EnvironmentProvisioner::class));

    expect($state->containers)->toHaveCount(1);
    expect($state->starts)->toBe(2);
    $this->assertDatabaseHas('project_environments', ['id' => $environment->id, 'status' => 'running', 'runtime_generation' => 1, 'workspace_reference' => $originalWorkspace]);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/start'));
});

it('rejects an incompatible Docker API before creating resources', function () {
    $operation = pendingEnvironmentStart();
    Http::preventStrayRequests();
    Http::fake(['http://localhost/version' => Http::response(['ApiVersion' => '1.40', 'MinAPIVersion' => '1.24'])]);

    expect(fn () => (new StartProjectEnvironment($operation->id))->handle(app(EnvironmentProvisioner::class)))
        ->toThrow(RuntimeException::class, 'The Docker API version is unsupported.');

    Http::assertSentCount(1);
});

it('fails exhausted recoveries without dispatching another attempt', function () {
    $operation = pendingEnvironmentStart();
    $operation->forceFill(['attempts' => 3, 'updated_at' => now()->subMinutes(6)])->save();
    Queue::fake();

    $this->artisan('environments:recover')->assertSuccessful();

    $this->assertDatabaseHas('environment_operations', ['id' => $operation->id, 'status' => 'failed']);
    Queue::assertNothingPushed();
});
