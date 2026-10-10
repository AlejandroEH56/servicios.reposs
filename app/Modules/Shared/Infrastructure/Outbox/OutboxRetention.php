<?php

namespace App\Modules\Shared\Infrastructure\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OutboxRetention
{
    /** @return array{published: int, inbox: int} */
    public function prune(int $limit, string $operator): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Batch size must be 1..500.');
        }

        return DB::transaction(function () use ($limit, $operator): array {
            $published = DB::table('compartido_mensajes_salida')->where('estado', 'PUBLISHED')
                ->where('publicado_en', '<', now()->subDays(config('modernization.outbox.published_retention_days')))
                ->orderBy('publicado_en')->orderBy('id')->limit($limit)->lockForUpdate()->pluck('id');
            $deleted = DB::table('compartido_mensajes_salida')->whereIn('id', $published)->where('estado', 'PUBLISHED')->delete();
            $inbox = DB::table('compartido_bandeja_entrada')
                ->where('procesado_en', '<', now()->subDays(config('modernization.outbox.inbox_retention_days')))
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('compartido_mensajes_salida')
                        ->whereColumn('compartido_mensajes_salida.id', 'compartido_bandeja_entrada.id_evento');
                })->orderBy('procesado_en')->orderBy('id_evento')->orderBy('consumidor')
                ->limit($limit)->lockForUpdate()->get(['consumidor', 'id_evento']);
            $removed = 0;
            foreach ($inbox as $row) {
                $removed += DB::table('compartido_bandeja_entrada')->where('consumidor', $row->consumidor)->where('id_evento', $row->id_evento)->delete();
            }
            DB::table('compartido_registros_auditoria')->insert([
                'id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'id_identidad_actor' => null,
                'nombre_contexto' => 'Shared', 'accion' => 'OUTBOX_RETENTION', 'tipo_sujeto' => 'Outbox',
                'id_sujeto' => 'retention', 'id_correlacion' => (string) Str::uuid(),
                'metadatos' => json_encode(['operator' => $operator, 'published' => $deleted, 'inbox' => $removed], JSON_THROW_ON_ERROR),
            ]);

            return ['published' => $deleted, 'inbox' => $removed];
        });
    }
}
