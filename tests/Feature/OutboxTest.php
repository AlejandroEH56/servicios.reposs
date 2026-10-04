<?php

namespace Tests\Feature;

use App\Modules\Shared\Infrastructure\Outbox\OutboxProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $type = 'test.v1'): string
    {
        $id = (string) Str::ulid();
        DB::table('compartido_mensajes_salida')->insert(['id' => $id, 'nombre_contexto' => 'Test', 'tipo_agregado' => 'Test',
            'id_agregado' => (string) Str::ulid(), 'tipo_evento' => $type, 'estado' => 'PENDING', 'ocurrido_en' => now(), 'disponible_en' => now(),
            'contenido' => json_encode(['eventId' => $id, 'type' => $type, 'version' => 1], JSON_THROW_ON_ERROR)]);

        return $id;
    }

    private function audit(array $event): void
    {
        DB::table('compartido_registros_auditoria')->insert(['id' => (string) Str::ulid(), 'ocurrido_en' => now(),
            'nombre_contexto' => 'Test', 'accion' => 'EFFECT', 'tipo_sujeto' => 'Test', 'id_sujeto' => $event['eventId']]);
    }

    public function test_publication_and_inbox_suppress_duplicate_delivery(): void
    {
        $id = $this->event();
        $worker = new OutboxProcessor(['test.v1' => ['test-consumer' => $this->audit(...)]]);
        $this->assertSame(1, $worker->runOnce());
        $this->assertDatabaseHas('compartido_mensajes_salida', ['id' => $id, 'estado' => 'PUBLISHED']);
        DB::table('compartido_mensajes_salida')->where('id', $id)->update(['estado' => 'PENDING']);
        $this->assertSame(1, $worker->runOnce());
        $this->assertDatabaseCount('compartido_registros_auditoria', 1);
        $this->assertDatabaseCount('compartido_bandeja_entrada', 1);
        $this->assertIsInt(Cache::get(config('modernization.health.outbox_heartbeat_key')));
    }

    public function test_crash_rolls_back_effect_and_inbox_then_retries_with_redacted_error(): void
    {
        $id = $this->event();
        $worker = new OutboxProcessor(['test.v1' => ['consumer' => function (array $event): void {
            $this->audit($event);
            throw new RuntimeException('sensitive-token@example.test');
        }]]);
        $worker->runOnce();
        $this->assertDatabaseCount('compartido_registros_auditoria', 0);
        $this->assertDatabaseCount('compartido_bandeja_entrada', 0);
        $this->assertDatabaseHas('compartido_mensajes_salida', ['id' => $id, 'estado' => 'PENDING', 'intentos' => 1, 'ultimo_error' => 'HANDLER_FAILED']);
        $this->assertSame(0, $worker->runOnce());
        $this->travel(6)->seconds();
        (new OutboxProcessor(['test.v1' => ['consumer' => $this->audit(...)]]))->runOnce();
        $this->assertDatabaseCount('compartido_registros_auditoria', 1);
        $this->assertDatabaseHas('compartido_mensajes_salida', ['id' => $id, 'estado' => 'PUBLISHED', 'intentos' => 2]);
    }

    public function test_poison_event_becomes_failed_and_audited_replay_resets_it(): void
    {
        $id = $this->event('unknown.v1');
        config(['modernization.outbox.max_attempts' => 2]);
        $worker = new OutboxProcessor;
        $worker->runOnce();
        $this->travel(6)->seconds();
        $worker->runOnce();
        $this->assertDatabaseHas('compartido_mensajes_salida', ['id' => $id, 'estado' => 'FAILED', 'intentos' => 2]);
        $worker->replay($id, (string) Str::ulid());
        $this->assertDatabaseHas('compartido_mensajes_salida', ['id' => $id, 'estado' => 'PENDING', 'intentos' => 0]);
        $this->assertDatabaseHas('compartido_registros_auditoria', ['accion' => 'OUTBOX_REPLAY', 'id_sujeto' => $id]);
    }

    public function test_expired_lease_is_reclaimed_and_stale_owner_cannot_publish(): void
    {
        $id = $this->event();
        $worker = new OutboxProcessor(['test.v1' => ['consumer' => $this->audit(...)]]);
        $old = $worker->claim()[0];
        $this->assertCount(0, $worker->claim());
        $this->travel(61)->seconds();
        $new = $worker->claim()[0];
        $this->assertNotSame($old->bloqueado_por, $new->bloqueado_por);
        $worker->deliver($old);
        $this->assertDatabaseCount('compartido_registros_auditoria', 0);
        $worker->deliver($new);
        $this->assertDatabaseHas('compartido_mensajes_salida', ['id' => $id, 'estado' => 'PUBLISHED']);
        $this->assertDatabaseCount('compartido_registros_auditoria', 1);
    }
}
