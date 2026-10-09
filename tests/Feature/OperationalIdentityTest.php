<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\IAM\Domain\IdentityState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OperationalIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_cannot_use_operational_commands(): void
    {
        config(['modernization.operations.enabled' => false]);
        $user = User::factory()->create();
        $this->expectException(RuntimeException::class);
        $this->artisan('iam:state', ['identity' => $user->id, 'state' => 'SUSPENDIDA', '--reason' => 'SECURITY_REVIEW']);
    }

    public function test_suspend_and_rehabilitate_preserve_identity_and_revoke_previous_session(): void
    {
        config(['modernization.operations.enabled' => true]);
        $user = User::factory()->create();
        $session = ['iam_authenticated_at' => now()->timestamp, 'iam_last_activity' => now()->timestamp, 'iam_authorization_version' => 1];
        $this->artisan('iam:state', ['identity' => $user->id, 'state' => 'SUSPENDIDA', '--reason' => 'SECURITY_REVIEW'])->assertSuccessful();
        $this->assertSame(2, $user->fresh()->version_autorizacion);
        $this->assertDatabaseHas('compartido_registros_auditoria', ['accion' => 'IDENTITY_STATE_CHANGED', 'id_sujeto' => $user->id]);
        $this->artisan('iam:state', ['identity' => $user->id, 'state' => 'ACTIVA', '--reason' => 'REHABILITATION'])->assertFailed();
        $this->artisan('iam:state', ['identity' => $user->id, 'state' => 'PENDIENTE', '--reason' => 'REHABILITATION'])->assertSuccessful();
        $this->assertSame(IdentityState::Pending, $user->fresh()->estado);
        $user->refresh();
        $user->estado = IdentityState::Active;
        $user->save();
        $this->actingAs($user)->withSession($session)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_group_proof_expires_at_exact_five_minute_boundary(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create();
        $session = ['iam_authenticated_at' => now()->timestamp, 'iam_last_activity' => now()->timestamp, 'iam_authorization_version' => 1];
        $this->actingAs($user)->withSession($session)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.sessionExpiresAt', now()->addMinutes(5)->toISOString());
        $this->travel(300)->seconds();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_operator_replay_is_audited_and_runtime_replay_denied(): void
    {
        $id = (string) Str::ulid();
        DB::table('compartido_mensajes_salida')->insert([
            'id' => $id, 'nombre_contexto' => 'Test', 'tipo_agregado' => 'Test', 'id_agregado' => $id,
            'tipo_evento' => 'test.v1', 'contenido' => '{}', 'estado' => 'FAILED', 'ocurrido_en' => now(), 'intentos' => 5,
        ]);
        config(['modernization.operations.enabled' => true]);
        $this->artisan('outbox:replay', ['event' => $id])->assertSuccessful();
        $this->assertDatabaseHas('compartido_mensajes_salida', ['id' => $id, 'estado' => 'PENDING', 'intentos' => 0]);
        $audit = DB::table('compartido_registros_auditoria')->where('accion', 'OUTBOX_REPLAY')->first();
        $this->assertNull($audit->id_identidad_actor);
        $this->assertSame('AlejandroEH56', json_decode($audit->metadatos, true)['operator']);
        config(['modernization.operations.enabled' => false]);
        $this->expectException(RuntimeException::class);
        $this->artisan('outbox:replay', ['event' => $id]);
    }
}
