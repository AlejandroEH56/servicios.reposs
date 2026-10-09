<?php

return [
    'storage' => [
        'max_bytes' => 10 * 1024 * 1024,
        'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'text/plain'],
        'scanner_host' => env('CLAMAV_HOST', 'antivirus'),
        'scanner_port' => (int) env('CLAMAV_PORT', 3310),
    ],
    'telemetry' => [
        'enabled' => (bool) env('OTEL_ENABLED', false),
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://collector:4318'),
    ],
    'sessions' => ['idle_seconds' => 1800, 'absolute_seconds' => 28800, 'group_freshness_seconds' => 300],
    'operations' => [
        'enabled' => (bool) env('MODERNIZATION_OPERATIONS_ENABLED', false),
        'owner' => env('MODERNIZATION_OPERATIONS_OWNER', 'AlejandroEH56'),
    ],
    'outbox' => [
        'lease_seconds' => 60,
        'max_attempts' => 5,
        'published_retention_days' => 30,
        'inbox_retention_days' => 90,
    ],
    'health' => [
        'storage_disk' => env('HEALTH_STORAGE_DISK', 'local'),
        'outbox_heartbeat_key' => 'modernization.outbox.last_success_at',
        'outbox_heartbeat_ttl' => (int) env('OUTBOX_HEARTBEAT_TTL', 120),
    ],
];
