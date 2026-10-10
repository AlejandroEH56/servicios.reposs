<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\IAM\Application\AuthorizationAdministration;
use App\Modules\IAM\Application\AuthorizationDirectory;
use App\Modules\IAM\Application\IdentityProvisioner;
use App\Modules\IAM\Application\VerifiedIdentity;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Application\ProfileProjector;
use App\Modules\Shared\Infrastructure\Outbox\OutboxProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SprintTwoOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->administrator = User::factory()->create();
        DB::table('iam_cuentas_externas')->insert(['id' => (string) Str::ulid(), 'id_identidad' => $this->administrator->id,
            'proveedor' => 'entra', 'id_inquilino' => (string) Str::uuid(), 'sujeto_proveedor' => (string) Str::uuid(),
            'creado_en' => now(), 'actualizado_en' => now()]);
        app(AuthorizationAdministration::class)->bootstrap($this->administrator->id, 'TEST_OPERATOR');
    }

    private function login(User $user): void
    {
        $user = $user->fresh();
        $this->actingAs($user)->withSession(['iam_authenticated_at' => now()->timestamp, 'iam_last_activity' => now()->timestamp,
            'iam_authorization_version' => $user->version_autorizacion]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    private function execute(string $operation, ?string $id, array $data): array
    {
        return app(OrganizationDirectory::class)->execute($operation, $id, $data, $this->administrator->id, (string) Str::uuid());
    }

    /** @return array<string, mixed> */
    private function employee(User $user, bool $adviser = false): array
    {
        return $this->execute('createEmployee', null, ['identityId' => $user->id, 'givenNames' => 'Persona de ensayo',
            'firstSurname' => 'Organization', 'isAdviser' => $adviser]);
    }

    private function grant(User $user, string $code): void
    {
        app(AuthorizationAdministration::class)->changeAssignment($user->id, DB::table('iam_roles')->where('codigo', $code)->value('id'),
            $user->fresh()->version_autorizacion, true, null, $this->administrator->id, (string) Str::uuid());
    }

    public function test_employee_reads_and_degree_writes_are_scoped_and_swapped_resource_ids_are_rejected(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $own = $this->employee($owner);
        $foreign = $this->employee($other);
        $this->grant($owner, 'EMPLEADO');
        $level = $this->execute('createLevel', null, ['code' => 'TEST_LEVEL', 'name' => 'Nivel de ensayo', 'rank' => 1]);
        $foreign = $this->execute('addDegree', $foreign['id'], ['expectedVersion' => 1, 'levelId' => $level['id'], 'program' => 'Programa de ensayo']);
        $this->login($owner);
        $this->getJson('/api/v1/organization/employees')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own['id']);
        $this->getJson('/api/v1/organization/employees/'.$foreign['id'])->assertForbidden();
        $this->postJson('/api/v1/organization/employees/'.$foreign['id'].'/degrees', ['expectedVersion' => 2, 'levelId' => $level['id'], 'program' => 'Other'])->assertForbidden();
        $this->deleteJson('/api/v1/organization/employees/'.$own['id'].'/degrees/'.$foreign['degrees'][0]['id'], ['expectedVersion' => 1])->assertNotFound();
        $this->postJson('/api/v1/organization/employees/'.$own['id'].'/degrees', [
            'expectedVersion' => 1, 'levelId' => $level['id'], 'program' => 'Programa propio', 'obtainedOn' => today()->toDateString(),
        ])->assertOk()->assertJsonCount(1, 'data.degrees')->assertJsonPath('data.version', 2);
        $this->postJson('/api/v1/organization/employees/'.$own['id'].'/degrees', ['expectedVersion' => 1, 'levelId' => $level['id'], 'program' => 'Stale'])->assertConflict();
        $this->getJson('/api/v1/organization/units')->assertForbidden();
        $this->postJson('/api/v1/organization/employees/'.$own['id'].'/positions', [])->assertForbidden();
    }

    public function test_adviser_read_permission_is_limited_to_confirmed_active_employees_and_omits_contact_data(): void
    {
        $reviewer = User::factory()->create();
        $this->grant($reviewer, 'DRPSS_REVISOR');
        $confirmed = $this->employee(User::factory()->create(), true);
        $this->employee(User::factory()->create(), false);
        $this->login($reviewer);
        $response = $this->getJson('/api/v1/organization/advisers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $confirmed['id']);
        $this->assertArrayNotHasKey('email', $response->json('data.0'));
        $this->assertArrayNotHasKey('identityId', $response->json('data.0'));
        $this->getJson('/api/v1/organization/employees/'.$confirmed['id'])->assertForbidden();
        $this->postJson('/api/v1/organization/units', [])->assertForbidden();
    }

    public function test_job_title_projection_is_idempotent_preserves_manual_positions_and_never_grants_roles(): void
    {
        $tenant = (string) Str::uuid();
        $object = (string) Str::uuid();
        $provisioner = app(IdentityProvisioner::class);
        $identityId = $provisioner->provision(new VerifiedIdentity($tenant, $object, 'Institutional fixture', 'jobtitle@example.test', null, 'Docente / Jefe', true), (string) Str::uuid());
        $this->assertDatabaseCount('organizacion_empleados', 0);
        $user = User::findOrFail($identityId);
        $employee = $this->employee($user);
        $this->assertCount(2, $employee['positions']);
        $unit = $this->execute('createUnit', null, ['code' => 'UNIT_TEST', 'name' => 'Unidad de ensayo', 'kind' => 'DEPARTMENT', 'active' => true, 'parentId' => null]);
        $employee = $this->execute('assignPosition', $employee['id'], ['expectedVersion' => $employee['version'], 'unitId' => $unit['id'], 'name' => 'Puesto manual', 'startsOn' => today()->toDateString()]);
        $provisioner->provision(new VerifiedIdentity($tenant, $object, 'Institutional fixture', 'jobtitle@example.test', null, 'Coordinador / Docente', true), (string) Str::uuid());
        $processor = app(OutboxProcessor::class);
        foreach ($processor->claim() as $event) {
            $processor->deliver($event);
        }
        $fresh = app(OrganizationDirectory::class)->employee($employee['id']);
        $active = array_values(array_filter($fresh['positions'], fn (array $position) => $position['active']));
        $this->assertCount(3, $active);
        $this->assertSame(['Coordinador', 'Docente'], array_column(array_values(array_filter($active, fn (array $position) => $position['source'] === 'MICROSOFT_JOBTITLE')), 'name'));
        $this->assertTrue(collect($active)->contains('name', 'Puesto manual'));
        $this->assertSame([], app(AuthorizationDirectory::class)->effective($identityId)['roles']);
        app(ProfileProjector::class)->synchronize($identityId);
        $this->assertSame($fresh['version'], app(OrganizationDirectory::class)->employee($employee['id'])['version']);
        $provisioner->provision(new VerifiedIdentity($tenant, $object, 'Institutional fixture', 'jobtitle@example.test', null, null, true), (string) Str::uuid());
        foreach ($processor->claim() as $event) {
            $processor->deliver($event);
        }
        $cleared = app(OrganizationDirectory::class)->employee($employee['id']);
        $this->assertCount(1, array_filter($cleared['positions'], fn (array $position) => $position['active']));
        $this->assertSame(3, $cleared['profileVersion']);
        $this->assertSame(0, DB::table('compartido_mensajes_salida')->where('estado', 'FAILED')->count());
        $this->assertStringNotContainsString('jobtitle@example.test', DB::table('compartido_mensajes_salida')->where('tipo_evento', 'iam.profile-synchronized.v1')->first()->contenido);
    }

    public function test_cycles_inactive_parents_and_stale_tree_versions_are_rejected(): void
    {
        $root = $this->execute('createUnit', null, ['code' => 'ROOT', 'name' => 'Root', 'kind' => 'ROOT', 'active' => true, 'parentId' => null]);
        $child = $this->execute('createUnit', null, ['code' => 'CHILD', 'name' => 'Child', 'kind' => 'DEPARTMENT', 'active' => true, 'parentId' => $root['id']]);
        $this->login($this->administrator);
        $update = ['expectedVersion' => 1, 'code' => 'ROOT', 'name' => 'Root', 'kind' => 'ROOT', 'active' => true, 'parentId' => $child['id']];
        $this->putJson('/api/v1/organization/units/'.$root['id'], $update)->assertConflict();
        $update['parentId'] = $root['id'];
        $this->putJson('/api/v1/organization/units/'.$root['id'], $update)->assertConflict();
        $update['parentId'] = null;
        $update['active'] = false;
        $this->putJson('/api/v1/organization/units/'.$root['id'], $update)->assertConflict();
        $update['active'] = true;
        $update['expectedVersion'] = 2;
        $this->putJson('/api/v1/organization/units/'.$root['id'], $update)->assertConflict();
        $this->assertDatabaseHas('organizacion_unidades', ['id' => $root['id'], 'id_padre' => null, 'version' => 1]);
    }

    public function test_identity_relinking_and_manual_changes_to_microsoft_positions_are_rejected(): void
    {
        $identityId = app(IdentityProvisioner::class)->provision(new VerifiedIdentity((string) Str::uuid(), (string) Str::uuid(),
            'Fixture', 'readonly-profile@example.test', null, 'Docente', true), (string) Str::uuid());
        $employee = $this->employee(User::findOrFail($identityId));
        $this->login($this->administrator);
        $this->postJson('/api/v1/organization/employees/'.$employee['id'].'/positions/'.$employee['positions'][0]['id'].'/finish',
            ['expectedVersion' => $employee['version'], 'endsOn' => today()->toDateString()])->assertConflict();
        $this->putJson('/api/v1/organization/employees/'.$employee['id'], [
            'expectedVersion' => $employee['version'], 'givenNames' => 'Fixture', 'firstSurname' => 'Updated', 'isAdviser' => false,
            'status' => 'ACTIVO', 'identityId' => $this->administrator->id,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('organizacion_empleados', ['id' => $employee['id'], 'id_identidad' => $identityId]);
    }

    public function test_organization_catalog_and_foreign_keys_use_domain_ulids(): void
    {
        $this->artisan('modernization:verify-identifiers', ['--enable-module' => ['organization']])->assertSuccessful();
        $this->login($this->administrator);
        $this->postJson('/api/v1/organization/levels', ['code' => 'ULID_LEVEL', 'name' => 'ULID level', 'rank' => 1])->assertCreated()
            ->assertJsonPath('data.version', 1);
        $level = DB::table('organizacion_niveles_academicos')->first();
        $this->assertTrue(Str::isUlid($level->id));
        $this->postJson('/api/v1/organization/levels', ['code' => 'ULID_LEVEL', 'name' => 'Duplicate', 'rank' => 2])->assertConflict();
    }
}
