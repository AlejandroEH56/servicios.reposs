<?php

namespace App\Modules\Shared\Infrastructure\Outbox;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OutboxMetrics
{
    /** @return array<string, int> */
    public function snapshot(): array
    {
        $table = DB::table('compartido_mensajes_salida');
        $oldest = (clone $table)->where('estado', 'PENDING')->where(function ($query): void {
            $query->whereNull('disponible_en')->orWhere('disponible_en', '<=', now());
        })->min('ocurrido_en');
        $heartbeat = Cache::get(config('modernization.health.outbox_heartbeat_key'));
        $age = is_numeric($heartbeat) ? max(0, now()->timestamp - (int) $heartbeat) : config('modernization.health.outbox_heartbeat_ttl') + 1;

        return [
            'pending' => (clone $table)->where('estado', 'PENDING')->count(),
            'processing' => (clone $table)->where('estado', 'PROCESSING')->count(),
            'failed' => (clone $table)->where('estado', 'FAILED')->count(),
            'expired_leases' => (clone $table)->where('estado', 'PROCESSING')->where('bloqueado_hasta', '<=', now())->count(),
            'oldest_eligible_seconds' => $oldest === null ? 0 : max(0, now()->timestamp - CarbonImmutable::parse($oldest)->timestamp),
            'heartbeat_age_seconds' => $age,
        ];
    }
}
