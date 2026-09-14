<?php

return [
    'terminal' => [
        'enabled' => (bool) env('ENVIRONMENTS_TERMINAL_ENABLED', false),
        'lifetime_seconds' => 900,
    ],
    'runtime' => [
        'enabled' => (bool) env('ENVIRONMENTS_RUNTIME_ENABLED', false),
        'socket' => env('ENVIRONMENTS_DOCKER_SOCKET', '/var/run/docker.sock'),
        'url' => env('ENVIRONMENTS_DOCKER_URL', 'http://localhost'),
        'tls_ca' => env('ENVIRONMENTS_DOCKER_TLS_CA'),
        'tls_cert' => env('ENVIRONMENTS_DOCKER_TLS_CERT'),
        'tls_key' => env('ENVIRONMENTS_DOCKER_TLS_KEY'),
        'namespace' => env('ENVIRONMENTS_RUNTIME_NAMESPACE', 'secops-hub'),
        'queue_connection' => env('ENVIRONMENTS_QUEUE_CONNECTION', 'redis'),
        'volume_driver' => env('ENVIRONMENTS_VOLUME_DRIVER', 'local'),
        'volume_size_option' => env('ENVIRONMENTS_VOLUME_SIZE_OPTION'),
        'allow_unlimited_storage' => (bool) env('ENVIRONMENTS_ALLOW_UNLIMITED_STORAGE', false),
    ],

    'max_per_project' => (int) env('ENVIRONMENTS_MAX_PER_PROJECT', 5),

    'approved_images' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ENVIRONMENTS_APPROVED_IMAGES', '')),
    ))) ?: ['secops-hub/kali:local'],

    'default_network_configuration' => [
        'version' => 1,
        'mode' => 'automatic',
        'dns_servers' => [],
        'search_domains' => [],
    ],

    'default_resource_limits' => [
        'version' => 1,
        'cpus' => 1,
        'memory_bytes' => 536870912,
        'storage_bytes' => 5368709120,
        'pids' => 128,
    ],

    'network_limits' => ['dns_servers' => 3, 'search_domains' => 6],

    /*
     * Controlled egress. "blocked" keeps the historic fully isolated network.
     * "filtered" gives the environment a routable network and enforces the
     * deny list below in iptables before the container is started.
     */
    'egress' => [
        'policies' => ['blocked', 'filtered'],

        'default' => [
            'version' => 1,
            'policy' => 'blocked',
            'allowed_targets' => [],
            'raw_sockets' => false,
        ],

        'max_allowed_targets' => (int) env('ENVIRONMENTS_EGRESS_MAX_TARGETS', 32),

        'helper_image' => env('ENVIRONMENTS_EGRESS_HELPER_IMAGE', 'secops-hub/egress:local'),

        'chain_prefix' => 'SECOPS-ENV-',

        'root_chain' => 'SECOPS-EGRESS',

        // Public resolvers used when the environment declares no DNS server.
        'resolvers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ENVIRONMENTS_EGRESS_RESOLVERS', '1.1.1.1,9.9.9.9')),
        ))),

        /*
         * Destinations an environment may never reach, whatever the allowlist
         * says: the platform's own networks, loopback, and the link-local range
         * that carries cloud instance metadata.
         */
        'blocked_destinations' => [
            '0.0.0.0/8',
            '10.0.0.0/8',
            '100.64.0.0/10',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '172.16.0.0/12',
            '192.0.0.0/24',
            '192.168.0.0/16',
            '198.18.0.0/15',
            '224.0.0.0/4',
            '240.0.0.0/4',
        ],
    ],

    'min_resource_limits' => [
        'cpus' => 0.1,
        'memory_bytes' => 67108864,
        'storage_bytes' => 1073741824,
        'pids' => 16,
    ],

    'max_resource_limits' => [
        'cpus' => (float) env('ENVIRONMENTS_MAX_CPUS', 4),
        'memory_bytes' => (int) env('ENVIRONMENTS_MAX_MEMORY_BYTES', 8589934592),
        'storage_bytes' => (int) env('ENVIRONMENTS_MAX_STORAGE_BYTES', 53687091200),
        'pids' => (int) env('ENVIRONMENTS_MAX_PIDS', 512),
    ],
];
