<?php

use App\Enums\EnvironmentStatus;
use App\Jobs\StopProjectEnvironment;
use App\Models\EnvironmentOperation;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\User;
use App\Services\EnvironmentProvisioner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['environments.runtime.enabled' => true, 'environments.runtime.namespace' => 'secops-test']);
});

function stoppableEnvironment(): ProjectEnvironment
{
    return ProjectEnvironment::factory()->for(Project::factory()->active())->create([
        'status' => 'running', 'desired_state' => 'running',
        'runtime_reference' => str_repeat('a', 64), 'runtime_generation' => 1,
        'workspace_reference' => 'saved-workspace',
    ]);
}

it('queues and deduplicates stops while immediately withdrawing shell access', function () {
    Queue::fake([StopProjectEnvironment::class]);
    $environment = stoppableEnvironment();
    $path = '/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id.'/stop';
    $response = $this->actingAs($environment->project->creator)->postJson($path)->assertAccepted()
        ->assertJsonPath('data.status', 'stopping')->assertJsonPath('data.desired_state', 'stopped')
        ->assertJsonPath('data.capabilities.shell', false);
    $this->postJson($path)->assertAccepted()->assertJsonPath('operation_id', $response->json('operation_id'));
    $this->assertDatabaseCount('environment_operations', 1);
    Queue::assertPushed(StopProjectEnvironment::class, 1);
});

it('requires authentication and ownership for stopping', function () {
    Queue::fake();
    $environment = stoppableEnvironment();
    $path = '/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id.'/stop';
    $this->postJson($path)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson($path)->assertNotFound();
    Queue::assertNothingPushed();
});

it('stops the owned container without deleting its writable layer or workspace', function () {
    $environment = stoppableEnvironment();
    $environment->forceFill(['status' => 'stopping', 'desired_state' => 'stopped'])->save();
    $operation = EnvironmentOperation::factory()->for($environment, 'environment')->create([
        'requested_by' => $environment->project->user_id, 'action' => 'stop',
    ]);
    $running = true;
    Http::preventStrayRequests();
    Http::fake(['http://localhost/*' => function (Request $request) use ($environment, &$running) {
        if (str_ends_with($request->url(), '/version')) {
            return Http::response(['ApiVersion' => '1.54']);
        }
        if ($request->method() === 'GET') {
            return Http::response(['Id' => str_repeat('a', 64), 'State' => ['Running' => $running], 'Config' => ['Labels' => [
                'secops.namespace' => 'secops-test', 'secops.project' => (string) $environment->project_id,
                'secops.environment' => (string) $environment->id, 'secops.generation' => '1',
            ]]]);
        }
        expect($request->method())->toBe('POST');
        expect($request->url())->toEndWith('/stop?t=20');
        $running = false;

        return Http::response(null, 204);
    }]);
    (new StopProjectEnvironment($operation->id))->handle(app(EnvironmentProvisioner::class));
    expect($environment->refresh()->status)->toBe(EnvironmentStatus::Stopped);
    expect($environment->runtime_reference)->toBe(str_repeat('a', 64));
    expect($environment->workspace_reference)->toBe('saved-workspace');
    expect($operation->refresh()->status)->toBe('completed');
    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
});

it('refuses to stop a container with conflicting ownership', function () {
    $environment = stoppableEnvironment();
    Http::preventStrayRequests();
    Http::fake([
        'http://localhost/version' => Http::response(['ApiVersion' => '1.54']),
        'http://localhost/v1.54/containers/*/json' => Http::response(['State' => ['Running' => true], 'Config' => ['Labels' => []]]),
    ]);
    expect(fn () => app(EnvironmentProvisioner::class)->stop($environment))->toThrow(RuntimeException::class, 'ownership');
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
});

it('recovers interrupted stop operations with the stop job', function () {
    Queue::fake([StopProjectEnvironment::class]);
    $environment = stoppableEnvironment();
    $operation = EnvironmentOperation::factory()->for($environment, 'environment')->create([
        'action' => 'stop', 'updated_at' => now()->subMinutes(6),
    ]);
    $this->artisan('environments:recover')->assertSuccessful();
    Queue::assertPushed(StopProjectEnvironment::class, fn ($job) => $job->operationId === $operation->id);
});
