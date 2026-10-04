<?php

namespace App\Modules\Shared\Infrastructure\Outbox;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OutboxProcessor
{
    /** @param array<string, array<string, callable(array<string,mixed>): void>> $handlers */
    public function __construct(private array $handlers = [], private ?string $connection = null) {}

    private function db(): Connection
    {
        return DB::connection($this->connection);
    }

    /** @return array<int, object> */
    public function claim(int $limit = 20): array
    {
        return $this->db()->transaction(function () use ($limit): array {
            $query = $this->db()->table('compartido_mensajes_salida')
                ->where(function ($query) {
                    $query->where(function ($pending) {
                        $pending->where('estado', 'PENDING')->where(function ($available) {
                            $available->whereNull('disponible_en')->orWhere('disponible_en', '<=', now());
                        });
                    })->orWhere(function ($expired) {
                        $expired->where('estado', 'PROCESSING')->where('bloqueado_hasta', '<=', now());
                    });
                })->orderBy('ocurrido_en')->orderBy('id')->limit($limit);
            if ($this->db()->getDriverName() === 'mysql') {
                $query->forceIndex('idx_outbox_order');
            }
            $query->lock($this->db()->getDriverName() === 'mysql' ? 'FOR UPDATE SKIP LOCKED' : true);
            $rows = $query->get()->all();
            $owner = (string) Str::uuid();
            foreach ($rows as $row) {
                $this->db()->table('compartido_mensajes_salida')->where('id', $row->id)->update([
                    'estado' => 'PROCESSING', 'bloqueado_por' => $owner,
                    'bloqueado_hasta' => now()->addSeconds(config('modernization.outbox.lease_seconds')),
                    'intentos' => $row->intentos + 1,
                ]);
                $row->bloqueado_por = $owner;
            }

            return $rows;
        });
    }

    public function deliver(object $claimed): void
    {
        try {
            $this->db()->transaction(function () use ($claimed): void {
                $row = $this->db()->table('compartido_mensajes_salida')->where('id', $claimed->id)->lockForUpdate()->first();
                if (! $row || $row->estado !== 'PROCESSING' || $row->bloqueado_por !== $claimed->bloqueado_por
                    || now()->greaterThanOrEqualTo($row->bloqueado_hasta)) {
                    return;
                }
                $payload = json_decode($row->contenido, true, 64, JSON_THROW_ON_ERROR);
                if (($payload['eventId'] ?? null) !== $row->id || ($payload['type'] ?? null) !== $row->tipo_evento
                    || ($payload['version'] ?? null) !== 1 || ! isset($this->handlers[$row->tipo_evento])) {
                    throw new RuntimeException('OUTBOX_INVALID_OR_UNHANDLED');
                }
                foreach ($this->handlers[$row->tipo_evento] as $consumer => $handler) {
                    $inbox = ['consumidor' => $consumer, 'id_evento' => $row->id];
                    if (! $this->db()->table('compartido_bandeja_entrada')->where($inbox)->exists()) {
                        $handler($payload);
                        $this->db()->table('compartido_bandeja_entrada')->insert($inbox + ['procesado_en' => now()]);
                    }
                }
                $this->db()->table('compartido_mensajes_salida')->where('id', $row->id)->update([
                    'estado' => 'PUBLISHED', 'publicado_en' => now(), 'ultimo_error' => null,
                    'bloqueado_por' => null, 'bloqueado_hasta' => null,
                ]);
            });
        } catch (Throwable) {
            $this->db()->transaction(function () use ($claimed): void {
                $row = $this->db()->table('compartido_mensajes_salida')->where('id', $claimed->id)->lockForUpdate()->first();
                if (! $row || $row->estado !== 'PROCESSING' || $row->bloqueado_por !== $claimed->bloqueado_por) {
                    return;
                }
                $terminal = $row->intentos >= config('modernization.outbox.max_attempts');
                $this->db()->table('compartido_mensajes_salida')->where('id', $row->id)->update([
                    'estado' => $terminal ? 'FAILED' : 'PENDING',
                    'fallido_en' => $terminal ? now() : null,
                    'disponible_en' => now()->addSeconds(min(300, 5 * 2 ** min($row->intentos - 1, 6))),
                    'ultimo_error' => 'HANDLER_FAILED',
                    'bloqueado_por' => null, 'bloqueado_hasta' => null,
                ]);
            });
        }
    }

    public function runOnce(): int
    {
        $rows = $this->claim();
        foreach ($rows as $row) {
            $this->deliver($row);
        }
        Cache::put(config('modernization.health.outbox_heartbeat_key'), now()->timestamp,
            config('modernization.health.outbox_heartbeat_ttl'));

        return count($rows);
    }

    public function replay(string $eventId, string $actorId): void
    {
        $this->db()->transaction(function () use ($eventId, $actorId): void {
            $row = $this->db()->table('compartido_mensajes_salida')->where('id', $eventId)->lockForUpdate()->first();
            if (! $row || $row->estado !== 'FAILED') {
                throw new RuntimeException('Only failed events can be replayed.');
            }
            $this->db()->table('compartido_mensajes_salida')->where('id', $eventId)->update([
                'estado' => 'PENDING', 'intentos' => 0, 'disponible_en' => now(),
                'fallido_en' => null, 'ultimo_error' => null, 'bloqueado_por' => null, 'bloqueado_hasta' => null,
            ]);
            $this->db()->table('compartido_registros_auditoria')->insert([
                'id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'id_identidad_actor' => $actorId,
                'nombre_contexto' => 'Shared', 'accion' => 'OUTBOX_REPLAY', 'tipo_sujeto' => 'Outbox',
                'id_sujeto' => $eventId, 'id_correlacion' => (string) Str::uuid(),
            ]);
        });
    }
}
