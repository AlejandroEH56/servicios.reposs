<?php

return [
    'sessions' => ['idle_seconds' => 1800, 'absolute_seconds' => 28800],
    'outbox' => [
        'lease_seconds' => 60,
        'max_attempts' => 5,
    ],
    'health' => [
        'storage_disk' => env('HEALTH_STORAGE_DISK', 'local'),
        'outbox_heartbeat_key' => 'modernization.outbox.last_success_at',
        'outbox_heartbeat_ttl' => (int) env('OUTBOX_HEARTBEAT_TTL', 120),
    ],
];
