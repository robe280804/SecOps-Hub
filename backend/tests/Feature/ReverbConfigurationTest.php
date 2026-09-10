<?php

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Reverb\Contracts\ApplicationProvider;

it('creates the Reverb broadcaster with the configured local endpoint', function () {
    config([
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);

    $broadcaster = Broadcast::connection('reverb');

    expect($broadcaster)->toBeInstanceOf(PusherBroadcaster::class);
    expect($broadcaster->getPusher()->getSettings())->toMatchArray([
        'host' => 'localhost', 'port' => 8080, 'scheme' => 'http',
    ]);
});

it('resolves the configured Reverb application through the installed server provider', function () {
    config([
        'reverb.apps.apps.0.app_id' => 'test-app',
        'reverb.apps.apps.0.key' => 'test-key',
        'reverb.apps.apps.0.secret' => 'test-secret',
    ]);

    $application = app(ApplicationProvider::class)->findByKey('test-key');

    expect($application->id())->toBe('test-app');
    expect($application->secret())->toBe('test-secret');
});
