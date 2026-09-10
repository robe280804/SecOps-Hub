<?php

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\User;
use Database\Seeders\ProjectEnvironmentSeeder;
use Database\Seeders\ProjectSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['environments.approved_images' => ['registry.example.test/tools:approved']]);
});

it('seeds demo environments using default or explicitly configured images', function (?string $images, string $expected) {
    $repository = Env::getRepository();
    $previous = $repository->get('ENVIRONMENTS_APPROVED_IMAGES');

    try {
        if ($images === null) {
            $repository->clear('ENVIRONMENTS_APPROVED_IMAGES');
        } else {
            $repository->set('ENVIRONMENTS_APPROVED_IMAGES', $images);
        }
        config(['environments' => require config_path('environments.php')]);
    } finally {
        if ($previous === null) {
            $repository->clear('ENVIRONMENTS_APPROVED_IMAGES');
        } else {
            $repository->set('ENVIRONMENTS_APPROVED_IMAGES', $previous);
        }
    }
    $admin = User::factory()->admin()->create();
    config(['admin.email' => $admin->email]);
    $this->seed(ProjectSeeder::class);

    $this->seed(ProjectEnvironmentSeeder::class);

    $this->assertDatabaseCount('project_environments', 4);
    expect(ProjectEnvironment::query()->pluck('base_image')->unique()->all())->toBe([$expected]);
})->with([
    'missing variable' => [null, 'ubuntu:24.04'],
    'empty variable' => ['', 'ubuntu:24.04'],
    'blank list' => [' ,  , ', 'ubuntu:24.04'],
    'custom list' => [' tools:approved, tools:other ', 'tools:approved'],
]);

it('seeds inactive environments only for admin demo projects and preserves local changes on rerun', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    config(['admin.email' => $admin->email]);
    $this->seed(ProjectSeeder::class);
    Project::factory()->for($admin, 'creator')->create(['name' => 'Real project']);
    Project::factory()->create(['name' => 'Demo: personal']);
    $this->seed(ProjectEnvironmentSeeder::class);
    $environment = ProjectEnvironment::query()->firstOrFail();
    $environment->update(['description' => 'Edited locally']);

    $this->seed(ProjectEnvironmentSeeder::class);

    $this->assertDatabaseCount('project_environments', 4);
    $this->assertDatabaseCount('projects', 6);
    $this->assertDatabaseCount('users', 2);
    expect($environment->fresh()->description)->toBe('Edited locally');
    foreach (ProjectEnvironment::all() as $demo) {
        expect($demo->isUnprovisioned())->toBeTrue();
        expect($demo->base_image)->toBe('registry.example.test/tools:approved');
        expect($demo->project->user_id)->toBe($admin->id);
    }
    Queue::assertNothingPushed();
});

it('refuses seeding without a configured admin', function () {
    config(['admin.email' => null]);

    expect(fn () => $this->seed(ProjectEnvironmentSeeder::class))->toThrow(RuntimeException::class, 'Run AdminSeeder');
    $this->assertDatabaseCount('project_environments', 0);
});

it('refuses granting demo environments to a non-admin account', function () {
    $user = User::factory()->create();
    config(['admin.email' => $user->email]);

    expect(fn () => $this->seed(ProjectEnvironmentSeeder::class))->toThrow(RuntimeException::class, 'Run AdminSeeder');
    $this->assertDatabaseCount('project_environments', 0);
});

it('refuses demo seeding outside development', function () {
    $this->app->instance('env', 'production');

    expect(fn () => app(ProjectEnvironmentSeeder::class)->run())->toThrow(RuntimeException::class, 'only be seeded in local or testing');
    $this->assertDatabaseCount('project_environments', 0);
});

it('requires an approved image and existing demo projects', function (string $missing) {
    $admin = User::factory()->admin()->create();
    config(['admin.email' => $admin->email]);
    if ($missing === 'image') {
        config(['environments.approved_images' => []]);
    }

    expect(fn () => $this->seed(ProjectEnvironmentSeeder::class))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('project_environments', 0);
})->with(['image', 'projects']);

it('leaves archived demo projects unchanged', function () {
    $admin = User::factory()->admin()->create();
    config(['admin.email' => $admin->email]);
    $project = Project::factory()->archived()->for($admin, 'creator')->create(['name' => 'Demo: personal']);

    $this->seed(ProjectEnvironmentSeeder::class);

    $this->assertDatabaseCount('project_environments', 0);
    $this->assertModelExists($project);
});

it('rolls back all demo environments if a project has exhausted its quota', function () {
    $admin = User::factory()->admin()->create();
    config(['admin.email' => $admin->email, 'environments.max_per_project' => 1]);
    $this->seed(ProjectSeeder::class);
    $lastProject = $admin->projects()->orderByDesc('id')->firstOrFail();
    $existing = ProjectEnvironment::factory()->for($lastProject)->create();

    expect(fn () => $this->seed(ProjectEnvironmentSeeder::class))->toThrow(RuntimeException::class, 'environment limit');

    $this->assertDatabaseCount('project_environments', 1);
    $this->assertModelExists($existing);
});
