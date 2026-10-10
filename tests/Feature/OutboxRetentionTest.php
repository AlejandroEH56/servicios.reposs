<?php

namespace Tests\Feature;

use App\Modules\Shared\Infrastructure\Outbox\OutboxProcessor;
use App\Modules\Shared\Infrastructure\Outbox\OutboxRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OutboxRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $state, int $days): string
    {
        $id = (string) Str::ulid();
        DB::table('compartido_mensajes_salida')->insert([
            'id' => $id, 'nombre_contexto' => 'Test', 'tipo_agregado' => 'Test', 'id_agregado' => $id,
            'tipo_evento' => 'test.v1', 'contenido' => json_encode(['eventId' => $id, 'type' => 'test.v1',
                'version' => 1, 'occurredAt' => now()->subDays($days)->toISOString()], JSON_THROW_ON_ERROR),
            'estado' => $state, 'ocurrido_en' => now()->subDays($days),
            'publicado_en' => $state === 'PUBLISHED' ? now()->subDays($days) : null,
        ]);

        return $id;
    }

    public function test_retention_preserves_failed_pending_and_inbox_deduplication_window(): void
    {
        $recent = $this->event('PUBLISHED', 29);
        $old = $this->event('PUBLISHED', 40);
        $failed = $this->event('FAILED', 100);
        $pending = $this->event('PENDING', 100);
        $orphan = (string) Str::ulid();
        foreach ([$old => 40, $failed => 100, $orphan => 100] as $id => $days) {
            DB::table('compartido_bandeja_entrada')->insert(['consumidor' => 'test', 'id_evento' => $id, 'procesado_en' => now()->subDays($days)]);
        }
        $this->assertSame(['published' => 1, 'inbox' => 1], (new OutboxRetention)->prune(500, 'test-operator'));
        foreach ([$recent, $failed, $pending] as $id) {
            $this->assertDatabaseHas('compartido_mensajes_salida', ['id' => $id]);
        }
        $this->assertDatabaseMissing('compartido_mensajes_salida', ['id' => $old]);
        $this->assertDatabaseHas('compartido_bandeja_entrada', ['id_evento' => $old]);
        $this->assertDatabaseHas('compartido_bandeja_entrada', ['id_evento' => $failed]);
        $this->assertDatabaseMissing('compartido_bandeja_entrada', ['id_evento' => $orphan]);
        $this->assertDatabaseHas('compartido_registros_auditoria', ['accion' => 'OUTBOX_RETENTION']);
    }

    public function test_batches_are_bounded_and_runtime_cannot_prune(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $this->event('PUBLISHED', 40);
        }
        config(['modernization.operations.enabled' => true]);
        $this->artisan('outbox:prune', ['--limit' => 2])->assertSuccessful();
        $this->assertDatabaseCount('compartido_mensajes_salida', 1);
        config(['modernization.operations.enabled' => false]);
        $this->expectException(RuntimeException::class);
        $this->artisan('outbox:prune');
    }

    public function test_event_older_than_inbox_window_cannot_reproduce_an_effect(): void
    {
        $id = $this->event('PENDING', 91);
        config(['modernization.outbox.max_attempts' => 1]);
        $processor = new OutboxProcessor(['test.v1' => ['test' => function (): void {
            $this->fail('Expired envelope must not reach the handler.');
        }]]);
        $processor->runOnce();
        $this->assertDatabaseHas('compartido_mensajes_salida', ['id' => $id, 'estado' => 'FAILED']);
        $this->assertDatabaseCount('compartido_bandeja_entrada', 0);
    }

    public function test_aggregate_metrics_and_alerts_detect_failed_backlog_and_missing_heartbeat(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->event('FAILED', 0);
        $this->event('PENDING', 1);
        Cache::forget(config('modernization.health.outbox_heartbeat_key'));
        $this->artisan('outbox:metrics', ['--check' => true])->assertFailed();
        DB::table('compartido_mensajes_salida')->delete();
        Cache::put(config('modernization.health.outbox_heartbeat_key'), now()->timestamp, 120);
        $this->artisan('outbox:metrics', ['--check' => true])->assertSuccessful();
    }
}
