<?php

use App\Enums\EnvironmentDesiredState;
use App\Enums\EnvironmentStatus;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['environments.terminal.enabled' => true]);
});

function terminalEnvironment(): ProjectEnvironment
{
    return ProjectEnvironment::factory()->for(Project::factory()->active())->create([
        'status' => EnvironmentStatus::Running, 'desired_state' => EnvironmentDesiredState::Running,
        'runtime_reference' => str_repeat('a', 64), 'runtime_generation' => 1,
    ]);
}

function terminalPath(ProjectEnvironment $environment): string
{
    return '/api/v1/projects/'.$environment->project_id.'/environments/'.$environment->id.'/terminal';
}

it('issues a short session for its owner and authorizes the exact runtime', function () {
    $this->freezeTime();
    $environment = terminalEnvironment();
    $response = $this->actingAs($environment->project->creator)->postJson(terminalPath($environment))
        ->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
    $ticket = explode('/', $response->json('data.url'))[2];
    expect($ticket)->toHaveLength(64);
    $authorized = $this->withHeader('X-Terminal-Ticket', $ticket)->getJson('/api/v1/terminal/authorize')
        ->assertOk()->assertJsonPath('data.container', str_repeat('a', 64));
    expect($authorized->json('data.labels')['secops.environment'])->toBe((string) $environment->id);
    $this->travel(901)->seconds();
    $this->getJson('/api/v1/terminal/authorize')->assertForbidden();
});

it('requires authentication and denies collaborators and unrelated users', function () {
    $environment = terminalEnvironment();
    $this->postJson(terminalPath($environment))->assertUnauthorized();
    $member = ProjectMembership::factory()->for($environment->project)->create();
    $this->actingAs($member->user)->postJson(terminalPath($environment))->assertForbidden();
    $this->actingAs(User::factory()->create())->postJson(terminalPath($environment))->assertNotFound();
});

it('rejects cross project binding and disabled or stopped terminals', function () {
    $environment = terminalEnvironment();
    $other = Project::factory()->active()->create();
    $this->actingAs($environment->project->creator)
        ->postJson('/api/v1/projects/'.$other->id.'/environments/'.$environment->id.'/terminal')->assertNotFound();
    config(['environments.terminal.enabled' => false]);
    $this->postJson(terminalPath($environment))->assertServiceUnavailable();
    config(['environments.terminal.enabled' => true]);
    $environment->forceFill(['status' => EnvironmentStatus::Stopped])->save();
    $this->postJson(terminalPath($environment))->assertConflict();
});

it('revokes access when the project is archived or the runtime changes or stops', function (string $change) {
    $environment = terminalEnvironment();
    $response = $this->actingAs($environment->project->creator)->postJson(terminalPath($environment))->assertCreated();
    $ticket = explode('/', $response->json('data.url'))[2];
    if ($change === 'archive') {
        $environment->project->update(['status' => 'archived']);
    } elseif ($change === 'generation') {
        $environment->forceFill(['runtime_generation' => 2])->save();
    } elseif ($change === 'restart') {
        $environment->forceFill(['last_observed_at' => now()])->save();
    } else {
        $environment->forceFill(['desired_state' => EnvironmentDesiredState::Stopped])->save();
    }
    $response = $this->withHeader('X-Terminal-Ticket', $ticket)->getJson('/api/v1/terminal/authorize');
    $response->assertStatus(in_array($change, ['generation', 'restart'], true) ? 403 : 409);
})->with(['archive', 'generation', 'stop', 'restart']);

it('binds terminal tickets to the issuing user and rejects invented tickets', function () {
    $environment = terminalEnvironment();
    $response = $this->actingAs($environment->project->creator)->postJson(terminalPath($environment))->assertCreated();
    $ticket = explode('/', $response->json('data.url'))[2];
    $this->actingAs(User::factory()->create())->withHeader('X-Terminal-Ticket', $ticket)
        ->getJson('/api/v1/terminal/authorize')->assertForbidden();
    $this->actingAs($environment->project->creator)->withHeader('X-Terminal-Ticket', str_repeat('x', 64))
        ->getJson('/api/v1/terminal/authorize')->assertForbidden();
});

it('rejects an existing terminal after browser logout', function () {
    $environment = terminalEnvironment();
    $this->actingAs($environment->project->creator, 'web')->withSession(['terminal' => true]);
    $this->withHeader('Origin', 'http://localhost:5173');
    $issued = $this->postJson(terminalPath($environment))->assertCreated();
    $this->withCredentials()->withUnencryptedCookie(config('session.cookie'), $issued->getCookie(config('session.cookie'), false)->getValue());
    $ticket = explode('/', $issued->json('data.url'))[2];
    $this->withHeader('X-Terminal-Ticket', $ticket)->getJson('/api/v1/terminal/authorize')->assertOk();
    $this->deleteJson('/api/v1/session')->assertNoContent();
    Auth::forgetGuards();
    $this->getJson('/api/v1/terminal/authorize')->assertUnauthorized();
});
