<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\IAM\Application\EntraAuthenticator;
use App\Modules\IAM\Application\VerifiedIdentity;
use App\Modules\IAM\Domain\IdentityState;
use App\Modules\IAM\Infrastructure\Persistence\ProvisionIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class MicrosoftAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function identity(string $email = 'person@example.test', string $object = '22222222-2222-4222-8222-222222222222'): VerifiedIdentity
    {
        return new VerifiedIdentity('11111111-1111-4111-8111-111111111111', $object, 'Persona de prueba', $email, $email);
    }

    private function provision(VerifiedIdentity $identity): string
    {
        return app(ProvisionIdentity::class)->provision($identity, (string) Str::uuid());
    }

    public function test_group_verified_identity_is_active_with_atomic_audit_and_outbox(): void
    {
        $id = $this->provision($this->identity());
        $this->assertTrue(Str::isUlid($id));
        $this->assertSame(IdentityState::Active, User::findOrFail($id)->estado);
        $this->assertDatabaseCount('iam_cuentas_externas', 1);
        $this->assertDatabaseHas('compartido_registros_auditoria', ['accion' => 'LOGIN_GRANTED', 'id_identidad_actor' => $id]);
        $this->assertDatabaseCount('compartido_mensajes_salida', 1);
        $this->assertStringNotContainsString('person@example.test', DB::table('compartido_mensajes_salida')->value('contenido'));
    }

    public function test_email_changes_keep_same_external_identity_and_do_not_repeat_link_event(): void
    {
        $id = $this->provision($this->identity());
        $this->assertSame($id, $this->provision($this->identity('new@example.test')));
        $this->assertDatabaseHas('iam_identidades', ['id' => $id, 'correo_normalizado' => 'new@example.test']);
        $this->assertDatabaseCount('compartido_mensajes_salida', 1);
    }

    public function test_pending_link_is_activated_and_object_id_in_other_tenant_is_separate(): void
    {
        $identity = $this->identity();
        $id = $this->provision($identity);
        User::findOrFail($id)->update(['estado' => IdentityState::Pending]);
        $this->assertSame($id, $this->provision($identity));
        $this->assertSame(IdentityState::Active, User::findOrFail($id)->estado);
        $other = $this->provision(new VerifiedIdentity('55555555-5555-4555-8555-555555555555', $identity->objectId, 'Other tenant', 'other@example.test', null));
        $this->assertNotSame($id, $other);
        $this->assertDatabaseCount('iam_cuentas_externas', 2);
    }

    public function test_idle_session_expires_at_thirty_minutes_even_with_longer_session_driver_ttl(): void
    {
        config(['session.lifetime' => 120]);
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['iam_authenticated_at' => now()->timestamp - 1900, 'iam_last_activity' => now()->timestamp - 1800])
            ->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_me_contract_has_exact_fields_and_session_expiry_without_token_material(): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['session.lifetime' => 120]);
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['iam_authenticated_at' => now()->timestamp, 'iam_last_activity' => now()->timestamp])
            ->getJson('/api/v1/me')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['data' => [
                'id' => $user->id, 'displayName' => $user->nombre_mostrado, 'email' => $user->correo_normalizado,
                'status' => 'ACTIVA', 'roles' => [], 'permissions' => [], 'scopes' => [],
                'sessionExpiresAt' => now()->addMinutes(30)->toISOString(),
            ]]);
    }

    public function test_email_collision_rejects_and_rolls_back_without_merging_accounts(): void
    {
        $id = $this->provision($this->identity());
        try {
            $this->provision($this->identity('person@example.test', '33333333-3333-4333-8333-333333333333'));
            $this->fail('Duplicate email must fail.');
        } catch (UniqueConstraintViolationException) {
            $this->assertDatabaseCount('iam_identidades', 1);
            $this->assertDatabaseCount('iam_cuentas_externas', 1);
            $this->assertDatabaseCount('compartido_registros_auditoria', 1);
            $this->assertSame($id, DB::table('iam_cuentas_externas')->value('id_identidad'));
        }
    }

    public function test_group_membership_does_not_reactivate_suspended_or_deactivated_identity(): void
    {
        $id = $this->provision($this->identity());
        foreach ([IdentityState::Suspended, IdentityState::Deactivated] as $state) {
            User::findOrFail($id)->update(['estado' => $state]);
            try {
                $this->provision($this->identity());
                $this->fail('Disabled account must fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('IAM_DISABLED', $exception->getMessage());
                $this->assertSame($state, User::findOrFail($id)->estado);
            }
        }
    }

    public function test_callback_consumes_state_once_and_creates_session_without_tokens(): void
    {
        $this->mock(EntraAuthenticator::class, function ($mock): void {
            $mock->shouldReceive('authenticate')->once()->with('code', 'verifier', 'nonce')->andReturn($this->identity());
        });
        $flow = ['state' => 'state', 'nonce' => 'nonce', 'verifier' => 'verifier', 'created_at' => now()->timestamp];
        $this->withSession(['microsoft_oidc' => $flow, 'url.intended' => 'https://evil.example'])->get('/auth/microsoft/callback?state=state&code=code')
            ->assertRedirect('/dashboard')->assertSessionMissing('microsoft_oidc')->assertSessionMissing('url.intended')
            ->assertSessionMissing('access_token')->assertSessionMissing('id_token');
        $this->assertAuthenticated();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.status', 'ACTIVA')
            ->assertJsonPath('data.scopes', [])->assertJsonStructure(['data' => ['sessionExpiresAt']]);
        $this->post('/auth/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/auth/microsoft/callback?state=state&code=code')->assertStatus(419);
    }

    public function test_invalid_or_expired_state_never_calls_provider(): void
    {
        $this->mock(EntraAuthenticator::class, fn ($mock) => $mock->shouldNotReceive('authenticate'));
        $this->withSession(['microsoft_oidc' => ['state' => 'state', 'nonce' => 'nonce', 'verifier' => 'verifier', 'created_at' => now()->timestamp - 601]])
            ->get('/auth/microsoft/callback?state=state&code=code')->assertStatus(419);
        $this->withSession(['microsoft_oidc' => ['state' => 'state', 'nonce' => 'nonce', 'verifier' => 'verifier', 'created_at' => now()->timestamp]])
            ->get('/auth/microsoft/callback?state%5B%5D=state&code=code')->assertStatus(419);
        $this->assertGuest();
    }

    public function test_revocation_and_absolute_session_expiry_block_me(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['iam_authenticated_at' => now()->timestamp - 28800, 'iam_last_activity' => now()->timestamp])
            ->getJson('/api/v1/me')->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json');
        $user->update(['estado' => IdentityState::Suspended]);
        $this->actingAs($user)->withSession(['iam_authenticated_at' => now()->timestamp, 'iam_last_activity' => now()->timestamp])
            ->getJson('/api/v1/me')->assertUnauthorized();
        $this->get('/auth/logout')->assertMethodNotAllowed();
    }
}
