<?php

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('returns 401 for unauthenticated lookup', function () {
    $project = Project::factory()->create();

    $this->getJson('/api/v1/projects/'.$project->id.'/collaborator-lookup?email=person@example.com')->assertUnauthorized();
});

it('allows owners to find an existing account by exact email without opening the user directory', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create(['email' => 'analyst@example.com']);
    User::factory()->create(['email' => 'other@example.com']);
    $response = $this->actingAs($project->creator)->getJson('/api/v1/projects/'.$project->id.'/collaborator-lookup?email=analyst%40example.com')
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $user->id);

    expect(array_keys($response->json('data.0')))->toBe(['id', 'name', 'email']);
    $this->getJson('/api/v1/users')->assertForbidden();
});

it('does not return the owner existing collaborators or absent accounts', function (string $candidate) {
    $project = Project::factory()->create();
    $membership = ProjectMembership::factory()->for($project)->create();
    $email = match ($candidate) {
        'owner' => $project->creator->email,
        'member' => $membership->user->email,
        default => 'absent@example.com',
    };

    $this->actingAs($project->creator)->getJson('/api/v1/projects/'.$project->id.'/collaborator-lookup?'.http_build_query(['email' => $email]))
        ->assertOk()->assertJsonCount(0, 'data');
})->with(['owner', 'member', 'absent']);

it('returns 403 to collaborators and 404 to unrelated admins', function () {
    $membership = ProjectMembership::factory()->create();
    $path = '/api/v1/projects/'.$membership->project_id.'/collaborator-lookup?email=analyst@example.com';

    $this->actingAs($membership->user)->getJson($path)->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->getJson($path)->assertNotFound();
});

it('returns 409 for lookups on archived projects', function () {
    $project = Project::factory()->archived()->create();

    $this->actingAs($project->creator)->getJson('/api/v1/projects/'.$project->id.'/collaborator-lookup?email=analyst@example.com')
        ->assertConflict();
});

it('returns 422 for missing malformed or overlong lookup emails', function (string $email, string $message) {
    $project = Project::factory()->create();

    $this->actingAs($project->creator)->getJson('/api/v1/projects/'.$project->id.'/collaborator-lookup?'.http_build_query(['email' => $email]))
        ->assertUnprocessable()->assertJsonValidationErrors(['email' => $message]);
})->with([
    ['', 'The email field is required.'],
    ['analyst', 'The email field must be a valid email address.'],
    [str_repeat('a', 256).'@example.com', 'The email field must not be greater than 255 characters.'],
]);

it('rate limits exact-email lookups per user', function () {
    $project = Project::factory()->create();
    $this->actingAs($project->creator);
    $path = '/api/v1/projects/'.$project->id.'/collaborator-lookup?email=absent@example.com';

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->getJson($path)->assertOk();
    }
    $this->getJson($path)->assertTooManyRequests();
});
