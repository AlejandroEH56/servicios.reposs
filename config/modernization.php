<?php

return [
    'health' => [
        'storage_disk' => env('HEALTH_STORAGE_DISK', 'local'),
        'outbox_heartbeat_key' => 'modernization.outbox.last_success_at',
        'outbox_heartbeat_ttl' => (int) env('OUTBOX_HEARTBEAT_TTL', 120),
    ],
];
