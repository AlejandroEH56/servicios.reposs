<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireCsrfToken;
use App\Models\User;
use App\Modules\IAM\Application\AuthorizationAdministration;
use App\Modules\IAM\Application\AuthorizationDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SprintTwoAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->administrator = User::factory()->create();
        DB::table('iam_cuentas_externas')->insert([
            'id' => (string) Str::ulid(), 'id_identidad' => $this->administrator->id, 'proveedor' => 'entra',
            'id_inquilino' => (string) Str::uuid(), 'sujeto_proveedor' => (string) Str::uuid(),
            'creado_en' => now(), 'actualizado_en' => now(),
        ]);
        app(AuthorizationAdministration::class)->bootstrap($this->administrator->id, 'TEST_OPERATOR');
    }

    private function login(User $user): void
    {
        $user = $user->fresh();
        $this->actingAs($user)->withSession(['iam_authenticated_at' => now()->timestamp, 'iam_last_activity' => now()->timestamp,
            'iam_authorization_version' => $user->version_autorizacion]);
    }

    private function role(string $code): string
    {
        return DB::table('iam_roles')->where('codigo', $code)->value('id');
    }

    public function test_commands_reject_unknown_root_and_nested_fields_without_writing(): void
    {
        $this->login($this->administrator);
        $this->postJson('/api/v1/iam/roles', ['code' => 'STRICT', 'name' => 'Strict fixture', 'automaticGrant' => true])->assertUnprocessable();
        $this->assertDatabaseMissing('iam_roles', ['codigo' => 'STRICT']);
        $role = $this->role('EMPLEADO');
        $permission = DB::table('iam_permisos')->where('codigo', 'organization.grados.gestionar')->value('id');
        $this->putJson('/api/v1/iam/roles/'.$role.'/permissions', ['expectedVersion' => 1,
            'permissions' => [['permissionId' => $permission, 'scope' => 'SELF', 'resourceOwner' => $this->administrator->id]],
        ])->assertUnprocessable();
        $this->assertSame(1, DB::table('iam_roles')->where('id', $role)->value('version'));
    }

    public function test_group_access_does_not_grant_local_privileges_and_every_iam_endpoint_denies_by_default(): void
    {
        $ordinary = User::factory()->create();
        $this->login($ordinary);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.roles', [])->assertJsonPath('data.permissions', []);
        foreach (['roles', 'permissions', 'identities', 'identities/'.$ordinary->id.'/roles'] as $route) {
            $this->getJson('/api/v1/iam/'.$route)->assertForbidden();
        }
        $this->postJson('/api/v1/iam/roles', ['code' => 'CUSTOM', 'name' => 'Custom'])->assertForbidden();
        $this->postJson('/api/v1/iam/identities/'.$ordinary->id.'/roles/'.$this->role('ADMIN_IAM'), ['expectedAuthorizationVersion' => 1])->assertForbidden();
        $this->assertDatabaseCount('iam_identidad_roles', 2);
    }

    public function test_grant_revoke_version_conflicts_history_and_stale_session_revocation(): void
    {
        $target = User::factory()->create();
        $role = $this->role('EMPLEADO');
        $this->login($this->administrator);
        $this->postJson('/api/v1/iam/identities/'.$target->id.'/roles/'.$role, ['expectedAuthorizationVersion' => 1])->assertOk()
            ->assertJsonPath('data.authorizationVersion', 2);
        $this->postJson('/api/v1/iam/identities/'.$target->id.'/roles/'.$role, ['expectedAuthorizationVersion' => 1])->assertConflict();
        $this->login($target);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.roles', ['EMPLEADO'])
            ->assertJsonFragment(['organization.empleados.consultar:self']);
        $this->login($this->administrator);
        $this->deleteJson('/api/v1/iam/identities/'.$target->id.'/roles/'.$role, ['expectedAuthorizationVersion' => 2])->assertOk();
        $this->actingAs($target->fresh())->withSession(['iam_authenticated_at' => now()->timestamp, 'iam_last_activity' => now()->timestamp, 'iam_authorization_version' => 2]);
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertSame(['GRANTED', 'REVOKED'], DB::table('iam_historial_roles')->where('id_identidad', $target->id)->orderBy('version_autorizacion')->pluck('accion')->all());
        $this->assertSame(2, DB::table('compartido_registros_auditoria')->where('id_sujeto', $target->id)->whereIn('accion', ['ROLE_GRANTED', 'ROLE_REVOKED'])->count());
    }

    public function test_expired_assignments_do_not_authorize_and_self_scope_never_becomes_global(): void
    {
        $target = User::factory()->create();
        $this->login($this->administrator);
        $this->postJson('/api/v1/iam/identities/'.$target->id.'/roles/'.$this->role('EMPLEADO'), [
            'expectedAuthorizationVersion' => 1, 'expiresAt' => now()->addMinute()->toISOString(),
        ])->assertOk();
        $authorization = app(AuthorizationDirectory::class);
        $this->assertTrue($authorization->allows($target->id, 'organization.grados.gestionar', $target->id));
        $this->assertFalse($authorization->allows($target->id, 'organization.grados.gestionar', $this->administrator->id));
        $this->assertFalse($authorization->allows($target->id, 'organization.grados.gestionar'));
        $this->travel(61)->seconds();
        $this->assertFalse($authorization->allows($target->id, 'organization.grados.gestionar', $target->id));
        $this->assertSame([], $authorization->effective($target->id)['roles']);
    }

    public function test_last_administrator_cannot_be_removed_expired_or_stripped_of_permission(): void
    {
        $this->login($this->administrator);
        $role = $this->role('ADMIN_IAM');
        $this->deleteJson('/api/v1/iam/identities/'.$this->administrator->id.'/roles/'.$role, [
            'expectedAuthorizationVersion' => $this->administrator->fresh()->version_autorizacion,
        ])->assertConflict();
        $this->postJson('/api/v1/iam/identities/'.$this->administrator->id.'/roles/'.$role, [
            'expectedAuthorizationVersion' => $this->administrator->fresh()->version_autorizacion,
            'expiresAt' => now()->addDay()->toISOString(),
        ])->assertUnprocessable();
        $this->putJson('/api/v1/iam/roles/'.$role.'/permissions', ['expectedVersion' => 1, 'permissions' => []])->assertConflict();
        $this->assertTrue(app(AuthorizationDirectory::class)->allows($this->administrator->id, 'iam.roles.gestionar'));
        $this->assertDatabaseHas('iam_roles', ['id' => $role, 'version' => 1]);
    }

    public function test_bundle_change_revokes_affected_sessions_and_unknown_permissions_and_scopes_are_rejected(): void
    {
        $target = User::factory()->create();
        $role = $this->role('EMPLEADO');
        app(AuthorizationAdministration::class)->changeAssignment($target->id, $role, 1, true, null, $this->administrator->id, (string) Str::uuid());
        $this->login($this->administrator);
        $this->putJson('/api/v1/iam/roles/'.$role.'/permissions', ['expectedVersion' => 1, 'permissions' => [
            ['permissionId' => (string) Str::ulid(), 'scope' => 'GLOBAL'],
        ]])->assertUnprocessable();
        $permission = DB::table('iam_permisos')->where('codigo', 'iam.roles.gestionar')->value('id');
        $this->putJson('/api/v1/iam/roles/'.$role.'/permissions', ['expectedVersion' => 1, 'permissions' => [
            ['permissionId' => $permission, 'scope' => 'SELF'],
        ]])->assertUnprocessable();
        $this->putJson('/api/v1/iam/roles/'.$role.'/permissions', ['expectedVersion' => 1, 'permissions' => []])->assertOk();
        $this->assertSame(3, $target->fresh()->version_autorizacion);
        $this->assertSame([], app(AuthorizationDirectory::class)->effective($target->id)['permissions']);
        $this->putJson('/api/v1/iam/roles/'.$role.'/permissions', ['expectedVersion' => 1, 'permissions' => []])->assertConflict();
    }

    public function test_bootstrap_is_console_only_and_cannot_be_reused_after_an_administrator_exists(): void
    {
        config(['modernization.operations.enabled' => true]);
        $this->artisan('iam:bootstrap-administration', ['identity' => $this->administrator->id])->assertFailed();
        config(['modernization.operations.enabled' => false]);
        $this->expectException(RuntimeException::class);
        $this->artisan('iam:bootstrap-administration', ['identity' => $this->administrator->id]);
    }

    public function test_iam_mutation_requires_csrf_even_from_same_origin(): void
    {
        $this->app->instance(RequireCsrfToken::class, new class($this->app, $this->app['encrypter']) extends RequireCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->login($this->administrator);
        $this->withSession(['_token' => 'sprint-two-token'])->withHeader('Sec-Fetch-Site', 'same-origin')
            ->postJson('/api/v1/iam/roles', ['code' => 'CSRF_PROBE', 'name' => 'Probe'])->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', 'sprint-two-token')->postJson('/api/v1/iam/roles', ['code' => 'CSRF_PROBE', 'name' => 'Probe'])->assertCreated();
        $this->assertDatabaseHas('iam_roles', ['codigo' => 'CSRF_PROBE']);
    }
}
