<?php

return [
    'max_per_project' => (int) env('ENVIRONMENTS_MAX_PER_PROJECT', 5),

    'approved_images' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ENVIRONMENTS_APPROVED_IMAGES', '')),
    ))) ?: ['ubuntu:24.04'],

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
