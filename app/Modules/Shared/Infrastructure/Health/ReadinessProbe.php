<?php

namespace App\Modules\Shared\Infrastructure\Health;

use App\Modules\Shared\Application\Ports\ReadinessCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ReadinessProbe implements ReadinessCheck
{
    public function isReady(): bool
    {
        try {
            DB::select('SELECT 1');
            $disk = Storage::disk(config('modernization.health.storage_disk'));
            $key = '.health/'.Str::uuid();
            try {
                $written = $disk->put($key, 'ready');
            } finally {
                $deleted = $disk->delete($key);
            }
            if (! $written || ! $deleted) {
                return false;
            }

            $heartbeat = Cache::get(config('modernization.health.outbox_heartbeat_key'));
            $now = now()->timestamp;

            return is_int($heartbeat)
                && $heartbeat <= $now
                && $heartbeat >= $now - config('modernization.health.outbox_heartbeat_ttl');
        } catch (Throwable) {
            return false;
        }
    }
}
