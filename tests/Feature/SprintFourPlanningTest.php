<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AcademicPlanning\Application\PlanningDirectory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SprintFourPlanningTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private const ROOT = '/api/v1/academic-planning/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->administrator = User::factory()->create();
        DB::table('iam_identidad_roles')->insert(['id_identidad' => $this->administrator->id,
            'id_rol' => DB::table('iam_roles')->where('codigo', 'ADMIN_PLANIFICACION')->value('id'),
            'id_identidad_otorgante' => $this->administrator->id, 'otorgado_en' => now()]);
        $this->login($this->administrator);
    }

    private function login(User $user): void
    {
        $user = $user->fresh();
        $this->actingAs($user)->withSession(['iam_authenticated_at' => now()->timestamp, 'iam_last_activity' => now()->timestamp,
            'iam_authorization_version' => $user->version_autorizacion]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    private function create(string $path, array $data): array
    {
        return $this->postJson(self::ROOT.$path, $data, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
    }

    /** @return array<string, mixed> */
    private function career(string $code = 'ISC'): array
    {
        return $this->create('catalogs/careers', ['code' => $code, 'name' => 'Ingeniería '.$code, 'shortName' => $code, 'active' => true]);
    }

    /** @return array<string, mixed> */
    private function semester(string $code = '2026-2'): array
    {
        return $this->create('semesters', ['code' => $code, 'name' => $code, 'startsOn' => '2026-08-01', 'endsOn' => '2026-12-18']);
    }

    /** @param array<string, mixed> $record */
    private function transition(array $record, string $action): TestResponse
    {
        return $this->postJson(self::ROOT.'semesters/'.$record['id'].'/'.$action, [], ['If-Match' => '"'.$record['version'].'"', 'Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_active_readers_have_no_write_permission_and_anonymous_has_no_access(): void
    {
        $reader = User::factory()->create();
        $this->login($reader);
        $this->getJson(self::ROOT.'semesters')->assertOk()->assertJsonPath('data.items', []);
        $this->postJson(self::ROOT.'semesters', [], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $this->getJson(self::ROOT.'responsibles')->assertForbidden();
        Auth::logout();
        $this->getJson(self::ROOT.'catalogs/careers')->assertUnauthorized();
    }

    public function test_catalog_create_retry_is_exactly_once_and_changed_payload_conflicts(): void
    {
        $key = (string) Str::uuid();
        $body = ['code' => 'ISC', 'name' => 'Ingeniería en Sistemas', 'active' => true];
        $first = $this->postJson(self::ROOT.'catalogs/careers', $body, ['Idempotency-Key' => $key])->assertCreated()->json();
        $this->postJson(self::ROOT.'catalogs/careers', $body, ['Idempotency-Key' => $key])->assertCreated()->assertExactJson($first);
        $body['name'] = 'Otro nombre';
        $this->postJson(self::ROOT.'catalogs/careers', $body, ['Idempotency-Key' => $key])->assertConflict();
        $this->assertDatabaseCount('planificacion_carreras', 1);
        $this->assertSame(1, DB::table('compartido_registros_auditoria')->where('nombre_contexto', 'AcademicPlanning')->count());
    }

    public function test_catalog_update_requires_current_version_and_rejects_unknown_fields(): void
    {
        $career = $this->career();
        $body = ['code' => 'ISC', 'name' => 'Sistemas actualizados', 'active' => true];
        $path = self::ROOT.'catalogs/careers/'.$career['id'];
        $this->patchJson($path, $body)->assertUnprocessable();
        $this->patchJson($path, $body + ['status' => 'ACTIVO'], ['If-Match' => '"1"'])->assertUnprocessable();
        $this->patchJson($path, $body, ['If-Match' => '"1"'])->assertOk()->assertHeader('ETag', '"2"')->assertJsonPath('data.shortName', null);
        $this->patchJson($path, $body, ['If-Match' => '"1"'])->assertConflict();
        $this->assertDatabaseHas('planificacion_carreras', ['id' => $career['id'], 'version' => 2]);
    }

    public function test_single_active_semester_requires_explicit_close_and_cannot_reopen(): void
    {
        $first = $this->semester();
        $second = $this->semester('2027-1');
        $active = $this->transition($first, 'activate')->assertOk()->assertJsonPath('data.status', 'ACTIVO')->json('data');
        $this->transition($second, 'activate')->assertConflict();
        $closed = $this->transition($active, 'close')->assertOk()->assertJsonPath('data.status', 'CERRADO')->json('data');
        $this->transition($closed, 'activate')->assertConflict();
        $this->transition($second, 'activate')->assertOk();
        $this->assertSame(1, DB::table('planificacion_semestres')->where('estado', 'ACTIVO')->count());
    }

    public function test_database_itself_rejects_two_active_semesters(): void
    {
        $first = $this->semester();
        $second = $this->semester('2027-1');
        DB::table('planificacion_semestres')->where('id', $first['id'])->update(['estado' => 'ACTIVO']);
        $this->expectException(QueryException::class);
        DB::table('planificacion_semestres')->where('id', $second['id'])->update(['estado' => 'ACTIVO']);
    }

    public function test_curriculum_enforces_specialty_career_and_null_specialty_uniqueness(): void
    {
        $career = $this->career();
        $other = $this->career('IGE');
        $specialty = $this->create('catalogs/specialties', ['careerId' => $other['id'], 'code' => 'ESP', 'name' => 'Especialidad', 'active' => true]);
        $subject = $this->create('catalogs/subjects', ['code' => 'MAT-1', 'name' => 'Matemáticas', 'satca' => '3-2-5', 'active' => true]);
        $body = ['careerId' => $career['id'], 'subjectId' => $subject['id'], 'active' => true];
        $this->postJson(self::ROOT.'catalogs/curricula', $body + ['specialtyId' => $specialty['id']], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->create('catalogs/curricula', $body);
        $this->postJson(self::ROOT.'catalogs/curricula', $body, ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('planificacion_reticulas', 1);
    }

    public function test_parents_with_active_dependents_cannot_be_disabled(): void
    {
        $career = $this->career();
        $this->create('catalogs/specialties', ['careerId' => $career['id'], 'code' => 'RED', 'name' => 'Redes', 'active' => true]);
        $this->patchJson(self::ROOT.'catalogs/careers/'.$career['id'], ['code' => 'ISC', 'name' => 'Sistemas', 'active' => false], ['If-Match' => '"1"'])->assertConflict();
        $this->assertDatabaseHas('planificacion_carreras', ['id' => $career['id'], 'activo' => true, 'version' => 1]);
    }

    public function test_groups_require_existing_open_semester_and_valid_career(): void
    {
        $career = $this->career();
        $semester = $this->semester();
        $this->create('catalogs/groups', ['careerId' => $career['id'], 'semesterId' => $semester['id'], 'name' => '1A', 'active' => true]);
        $this->transition($semester, 'archive')->assertOk();
        $this->postJson(self::ROOT.'catalogs/groups', ['careerId' => $career['id'], 'semesterId' => $semester['id'], 'name' => '1B', 'active' => true], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_holiday_types_and_foreign_keys_use_ulids_and_intervals_are_canonical(): void
    {
        $type = $this->create('catalogs/holiday-types', ['code' => 'OFICIAL', 'name' => 'Suspensión oficial', 'active' => true]);
        $this->assertTrue(Str::isUlid($type['id']));
        $holiday = $this->create('holidays', ['holidayTypeId' => $type['id'], 'name' => 'Día inhábil', 'startsAt' => '2026-11-02T00:00:00-06:00', 'endsAt' => '2026-11-03T00:00:00-06:00', 'active' => true]);
        $this->assertSame('2026-11-02T06:00:00.000000Z', $holiday['startsAt']);
        $this->postJson(self::ROOT.'holidays', ['holidayTypeId' => $type['id'], 'name' => 'Intervalo inválido', 'startsAt' => '2026-11-03T00:00:00Z', 'endsAt' => '2026-11-02T00:00:00Z', 'active' => true], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->artisan('modernization:verify-identifiers', ['--enable-module' => ['planning']])->assertSuccessful();
    }

    public function test_laboratory_rejects_missing_or_inactive_responsible_and_requires_capacity(): void
    {
        $body = ['code' => 'RED', 'name' => 'Redes', 'capacity' => 30, 'status' => 'ACTIVO'];
        $this->postJson(self::ROOT.'laboratories', $body + ['responsibleEmployeeId' => (string) Str::ulid()], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->create('laboratories', $body);
        $body['code'] = 'BAD';
        $body['capacity'] = 0;
        $this->postJson(self::ROOT.'laboratories', $body, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson(self::ROOT.'responsibles')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_responsibles_expose_only_active_employee_reference_and_validate_status(): void
    {
        $employee = (string) Str::ulid();
        DB::table('organizacion_empleados')->insert(['id' => $employee, 'id_identidad' => $this->administrator->id,
            'nombres' => 'Persona', 'primer_apellido' => 'De ensayo', 'estado' => 'ACTIVO', 'version' => 1,
            'creado_en' => now(), 'actualizado_en' => now()]);
        $this->getJson(self::ROOT.'responsibles')->assertOk()->assertExactJson(['data' => [['id' => $employee, 'displayName' => 'Persona De ensayo']]]);
        $body = ['code' => 'RED', 'name' => 'Redes', 'capacity' => 30, 'status' => 'ACTIVO', 'responsibleEmployeeId' => $employee];
        $this->create('laboratories', $body);
        DB::table('organizacion_empleados')->where('id', $employee)->update(['estado' => 'INACTIVO']);
        $body['code'] = 'RED-2';
        $this->postJson(self::ROOT.'laboratories', $body, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson(self::ROOT.'responsibles')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_catalog_permission_does_not_allow_semester_administration(): void
    {
        $permission = DB::table('iam_permisos')->where('codigo', 'planning.manage')->value('id');
        DB::table('iam_rol_permisos')->where('id_permiso', $permission)->delete();
        $this->career();
        $this->postJson(self::ROOT.'semesters', ['code' => '2026-2', 'name' => 'Sin permiso', 'startsOn' => '2026-08-01', 'endsOn' => '2026-12-18'], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_encrypted_cursor_is_bound_to_catalog_filters_and_literal_search(): void
    {
        $this->career('ISC');
        $this->career('IGE');
        $first = $this->getJson(self::ROOT.'catalogs/careers?pageSize=1')->assertOk()->json('data');
        $cursor = urlencode($first['nextCursor']);
        $this->getJson(self::ROOT.'catalogs/careers?pageSize=1&cursor='.$cursor)->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.nextCursor', null);
        $this->getJson(self::ROOT.'catalogs/subjects?pageSize=1&cursor='.$cursor)->assertUnprocessable();
        $this->getJson(self::ROOT.'catalogs/careers?pageSize=2&cursor='.$cursor)->assertUnprocessable();
        $this->getJson(self::ROOT.'catalogs/careers?q=%25')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson(self::ROOT.'catalogs/unknown')->assertNotFound();
        $this->getJson(self::ROOT.'catalogs/semesters')->assertNotFound();
    }

    public function test_permission_is_rechecked_for_replay_and_expired_keys_cannot_be_reused(): void
    {
        $key = (string) Str::uuid();
        $body = ['code' => 'ISC', 'name' => 'Sistemas', 'active' => true];
        $this->postJson(self::ROOT.'catalogs/careers', $body, ['Idempotency-Key' => $key])->assertCreated();
        DB::table('planificacion_claves_idempotencia')->update(['expira_en' => now()->subMinute()]);
        $this->postJson(self::ROOT.'catalogs/careers', $body, ['Idempotency-Key' => $key])->assertConflict();
        DB::table('iam_identidad_roles')->where('id_identidad', $this->administrator->id)->delete();
        $this->expectException(HttpException::class);
        app(PlanningDirectory::class)->execute('careers', 'create', null, $body, null, $key, $this->administrator->id, (string) Str::uuid());
    }

    public function test_invalid_dates_html_and_mass_assignment_do_not_write(): void
    {
        $this->postJson(self::ROOT.'semesters', ['code' => 'BAD', 'name' => 'Fechas', 'startsOn' => '2026-02-30', 'endsOn' => '2026-12-01'], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson(self::ROOT.'catalogs/careers', ['code' => 'BAD', 'name' => '<script>alert(1)</script>', 'active' => true], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson(self::ROOT.'semesters', ['code' => 'BAD', 'name' => 'Estado inyectado', 'startsOn' => '2026-01-01', 'endsOn' => '2026-06-01', 'status' => 'ACTIVO'], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('planificacion_semestres', 0);
        $this->assertDatabaseCount('planificacion_carreras', 0);
    }
}
