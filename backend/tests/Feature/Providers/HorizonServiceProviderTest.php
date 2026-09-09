<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(LazilyRefreshDatabase::class);

it('allows only administrators through the dashboard gate', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue();
    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
    expect(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});

it('renders the dashboard for administrators outside local development', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/horizon')->assertViewIs('horizon::layout');
});

it('returns 403 for regular users outside local development', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/horizon')->assertForbidden();
});

it('returns 403 for guests outside local development', function () {
    $this->get('/horizon')->assertForbidden();
});

it('allows local dashboard access without authentication', function () {
    $this->app->instance('env', 'local');

    $this->get('/horizon')->assertViewIs('horizon::layout');
});
