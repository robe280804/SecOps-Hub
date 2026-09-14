<?php

use App\Jobs\StartProjectEnvironment;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['environments.runtime.enabled' => true, 'environments.approved_images' => ['tools:approved']]);
});

it('accepts a start and deduplicates repeated requests without creating another operation', function () {
    Queue::fake([StartProjectEnvironment::class]);
    $environment = ProjectEnvironment::factory()->for(Project::factory()->active())->create(['base_image' => 'tools:approved']);
    $path = '/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id.'/start';

    $response = $this->actingAs($environment->project->creator)->postJson($path)
        ->assertAccepted()->assertJsonPath('data.status', 'provisioning')->assertJsonPath('data.desired_state', 'running');
    $this->postJson($path)->assertAccepted()->assertJsonPath('operation_id', $response->json('operation_id'));

    $this->assertDatabaseCount('environment_operations', 1);
    $this->assertDatabaseHas('project_environments', ['id' => $environment->id, 'status' => 'provisioning', 'runtime_reference' => null]);
    Queue::assertPushed(StartProjectEnvironment::class, 1);
});

it('requires authentication and refuses collaborators and unrelated users', function () {
    Queue::fake();
    $environment = ProjectEnvironment::factory()->for(Project::factory()->active())->create();
    $membership = ProjectMembership::factory()->for($environment->project)->create();
    $path = '/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id.'/start';

    $this->postJson($path)->assertUnauthorized();
    $this->actingAs($membership->user)->postJson($path)->assertForbidden();
    $this->actingAs(User::factory()->create())->postJson($path)->assertNotFound();

    $this->assertDatabaseCount('environment_operations', 0);
    Queue::assertNothingPushed();
});

it('rejects an environment bound to another project', function () {
    Queue::fake();
    $project = Project::factory()->active()->create();
    $environment = ProjectEnvironment::factory()->create();

    $this->actingAs($project->creator)->postJson('/api/v1/projects/'.$project->id.'/environments/'.$environment->id.'/start')->assertNotFound();

    $this->assertDatabaseCount('environment_operations', 0);
    Queue::assertNothingPushed();
});

it('rejects starts for inactive and archived projects', function (string $status) {
    Queue::fake();
    $environment = ProjectEnvironment::factory()->for(Project::factory()->state(['status' => $status]))->create();

    $this->actingAs($environment->project->creator)
        ->postJson('/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id.'/start')
        ->assertConflict()->assertJsonPath('message', 'Activate the project before starting an environment.');

    $this->assertDatabaseCount('environment_operations', 0);
    Queue::assertNothingPushed();
})->with(['inactive', 'archived']);

it('rejects a removed approved image before creating an operation', function () {
    Queue::fake();
    $environment = ProjectEnvironment::factory()->for(Project::factory()->active())->create();

    $this->actingAs($environment->project->creator)
        ->postJson('/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id.'/start')
        ->assertConflict()->assertJsonPath('message', 'The environment image is no longer approved.');

    $this->assertDatabaseCount('environment_operations', 0);
    Queue::assertNothingPushed();
});

it('leaves configuration unchanged when startup is disabled', function () {
    Queue::fake();
    config(['environments.runtime.enabled' => false]);
    $environment = ProjectEnvironment::factory()->for(Project::factory()->active())->create(['base_image' => 'tools:approved']);

    $this->actingAs($environment->project->creator)
        ->postJson('/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id.'/start')->assertServiceUnavailable();

    $this->assertDatabaseHas('project_environments', ['id' => $environment->id, 'status' => 'inactive']);
    $this->assertDatabaseCount('environment_operations', 0);
    Queue::assertNothingPushed();
});
