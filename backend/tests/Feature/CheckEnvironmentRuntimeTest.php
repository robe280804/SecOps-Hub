<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config([
        'environments.runtime.enabled' => true,
        'environments.runtime.queue_connection' => 'redis',
        'environments.runtime.socket' => '/var/run/docker.sock',
        'environments.approved_images' => ['tools:approved'],
    ]);
});

it('reports a healthy runtime only after database Redis Docker and image checks pass', function () {
    Redis::shouldReceive('connection')->once()->with('default')->andReturnSelf();
    Redis::shouldReceive('ping')->once()->andReturn(true);
    Http::preventStrayRequests();
    Http::fake([
        'http://localhost/version' => Http::response(['ApiVersion' => '1.54', 'MinAPIVersion' => '1.40']),
        'http://localhost/v1.54/info' => Http::response(['OSType' => 'linux']),
        'http://localhost/v1.54/images/tools%3Aapproved/json' => Http::response(['Id' => 'image-id']),
    ]);

    $this->artisan('environments:check')->expectsOutput('Environment runtime is ready.')->assertSuccessful();

    Http::assertSentCount(3);
});

it('reports a missing approved image without leaking Docker diagnostics', function () {
    Redis::shouldReceive('connection')->once()->andReturnSelf();
    Redis::shouldReceive('ping')->once()->andReturn(true);
    Http::preventStrayRequests();
    Http::fake([
        'http://localhost/version' => Http::response(['ApiVersion' => '1.54', 'MinAPIVersion' => '1.40']),
        'http://localhost/v1.54/info' => Http::response(['OSType' => 'linux']),
        'http://localhost/v1.54/images/tools%3Aapproved/json' => Http::response(['message' => 'private daemon detail'], 404),
    ]);

    $this->artisan('environments:check')->expectsOutput('Runtime check failed: approved images.')->assertFailed();

    Http::assertSentCount(3);
});

it('does not report a healthy worker when Horizon is stopped', function () {
    Redis::shouldReceive('connection')->once()->andReturnSelf();
    Redis::shouldReceive('ping')->once()->andReturn(true);
    $this->mock(MasterSupervisorRepository::class)->shouldReceive('all')->once()->andReturn([]);
    Http::preventStrayRequests();
    Http::fake([
        'http://localhost/version' => Http::response(['ApiVersion' => '1.54', 'MinAPIVersion' => '1.40']),
        'http://localhost/v1.54/info' => Http::response(['OSType' => 'linux']),
        'http://localhost/v1.54/images/tools%3Aapproved/json' => Http::response(['Id' => 'image-id']),
    ]);

    $this->artisan('environments:check --worker')->expectsOutput('Runtime check failed: Horizon worker.')->assertFailed();

    Http::assertSentCount(3);
});

it('rejects disabled startup before contacting any runtime service', function () {
    config(['environments.runtime.enabled' => false]);
    Redis::shouldReceive('connection')->never();
    Http::preventStrayRequests();
    Http::fake();

    $this->artisan('environments:check')->expectsOutput('Runtime check failed: configuration.')->assertFailed();

    Http::assertNothingSent();
});
