<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AcademicPlanning\Application\LaboratoryCalendar;
use App\Modules\AcademicPlanning\Application\PlanningDirectory;
use App\Modules\Organization\Application\OrganizationDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SprintNineLaboratoryRequestsTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/api/v1/laboratory-requests';

    private User $administrator;

    private User $teacher;

    private User $technician;

    private string $lab;

    private string $semester;

    private string $career;

    private string $subject;

    private string $group;

    private string $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-13T16:00:00Z'));
        $this->administrator = User::factory()->create();
        $this->teacher = User::factory()->create();
        $this->technician = User::factory()->create();
        foreach ([[$this->administrator, 'ADMIN_PLANIFICACION'], [$this->administrator, 'ADMIN_ORGANIZACION'],
            [$this->teacher, 'DOCENTE_SOLICITANTE'], [$this->technician, 'LABORATORISTA']] as [$identity, $role]) {
            $this->grant($identity, $role);
        }

        $employee = app(OrganizationDirectory::class)->execute('createEmployee', null, [
            'identityId' => $this->technician->id, 'givenNames' => 'Laboratorista', 'firstSurname' => 'Ensayo',
        ], $this->administrator->id, (string) Str::uuid());
        $this->semester = $this->planning('semesters', ['code' => 'S9', 'name' => 'Semestre de prueba', 'startsOn' => '2026-11-01', 'endsOn' => '2027-03-31']);
        app(PlanningDirectory::class)->execute('semesters', 'activate', $this->semester, [], 1, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid());
        $this->career = $this->planning('careers', ['code' => 'S9-C', 'name' => 'Carrera piloto', 'active' => true]);
        $this->subject = $this->planning('subjects', ['code' => 'S9-A', 'name' => 'Asignatura piloto', 'active' => true]);
        $this->planning('curricula', ['careerId' => $this->career, 'subjectId' => $this->subject, 'specialtyId' => null, 'active' => true]);
        $this->group = $this->planning('groups', ['name' => 'Grupo piloto', 'careerId' => $this->career, 'semesterId' => $this->semester, 'active' => true]);
        $this->lab = $this->planning('laboratories', ['code' => 'S9-L', 'name' => 'Laboratorio piloto', 'capacity' => 25, 'status' => 'ACTIVO',
            'careerId' => null, 'responsibleEmployeeId' => $employee['id']]);
        $this->schedule = app(LaboratoryCalendar::class)->publish($this->lab, null, $this->slot(), null, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid())['id'];
    }

    private function grant(User $identity, string $role): void
    {
        DB::table('iam_identidad_roles')->insert(['id_identidad' => $identity->id,
            'id_rol' => DB::table('iam_roles')->where('codigo', $role)->value('id'),
            'id_identidad_otorgante' => $this->administrator->id, 'otorgado_en' => now()]);
    }

    private function login(User $user): void
    {
        $this->actingAs($user)->withSession(['iam_authenticated_at' => now()->timestamp,
            'iam_last_activity' => now()->timestamp, 'iam_authorization_version' => $user->version_autorizacion]);
    }

    /** @param array<string, mixed> $data */
    private function planning(string $kind, array $data): string
    {
        return app(PlanningDirectory::class)->execute($kind, 'create', null, $data, null, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid())['id'];
    }

    /** @return array<string, mixed> */
    private function slot(): array
    {
        return ['semesterId' => $this->semester, 'weekday' => 1, 'startsAtLocal' => '07:00', 'endsAtLocal' => '11:00'];
    }

    /** @return array<string, mixed> */
    private function input(string $type = 'VARIA'): array
    {
        return ['type' => $type, 'laboratoryId' => $this->lab, 'startAt' => '2026-11-16T07:00:00-06:00', 'endAt' => '2026-11-16T08:00:00-06:00',
            'title' => 'Solicitud de ensayo', 'purpose' => 'Actividad académica', 'attendees' => 12, 'supplies' => 'Material de ensayo'] + match ($type) {
                'PRACTICA' => ['practice' => ['careerId' => $this->career, 'subjectId' => $this->subject, 'groupId' => $this->group, 'name' => 'Práctica piloto', 'number' => '1']],
                'EXTRAORDINARIA' => ['extraordinary' => ['justification' => 'Actividad extraordinaria']],
                default => ['various' => ['useCode' => 'PROYECTO', 'description' => 'Trabajo de equipo']],
            };
    }

    /** @return array<string, string> */
    private function headers(?int $version = null, ?string $key = null): array
    {
        return ['Idempotency-Key' => $key ?? (string) Str::uuid()] + ($version === null ? [] : ['If-Match' => '"'.$version.'"']);
    }

    /** @return array<string, list<string>> */
    public static function types(): array
    {
        return ['práctica' => ['PRACTICA', 'practice', 'laboratorios_detalles_practica'],
            'varia' => ['VARIA', 'various', 'laboratorios_detalles_varios'],
            'extraordinaria' => ['EXTRAORDINARIA', 'extraordinary', 'laboratorios_detalles_extraordinarios']];
    }

    #[DataProvider('types')]
    public function test_legacy_subtypes_create_edit_and_submit_exactly_one_detail(string $type, string $detail, string $table): void
    {
        $this->login($type === 'EXTRAORDINARIA' ? $this->technician : $this->teacher);
        $input = $this->input($type);
        $headers = $this->headers();
        $draft = $this->postJson(self::ROOT, $input, $headers)->assertCreated()->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.status', 'BORRADOR')->assertJsonPath('data.type', $type)
            ->assertJsonPath('data.startAt', '2026-11-16T13:00:00.000000Z')->assertJsonPath('data.'.$detail, $input[$detail])->json();
        $this->postJson(self::ROOT, $input, $headers)->assertCreated()->assertExactJson($draft);
        $path = self::ROOT.'/'.$draft['data']['id'];
        $input['title'] = 'Título corregido';
        $this->postJson(self::ROOT, $input, $headers)->assertConflict();
        $this->patchJson($path, $input, $this->headers(2))->assertConflict();
        $this->patchJson($path, $input, $this->headers(1))->assertOk()->assertHeader('ETag', '"2"')->assertJsonPath('data.title', $input['title']);
        $headers = $this->headers(2);
        $submitted = $this->postJson($path.'/submit', [], $headers)->assertOk()->assertHeader('ETag', '"3"')->assertJsonPath('data.status', 'PENDIENTE')->json();
        $this->postJson($path.'/submit', [], $headers)->assertOk()->assertExactJson($submitted);
        $this->postJson($path.'/submit', [], $this->headers(3))->assertConflict();
        $this->patchJson($path, $input, $this->headers(3))->assertConflict();
        $this->assertDatabaseCount('laboratorios_solicitudes', 1);
        foreach (['laboratorios_detalles_practica', 'laboratorios_detalles_varios', 'laboratorios_detalles_extraordinarios'] as $candidate) {
            $this->assertDatabaseCount($candidate, $candidate === $table ? 1 : 0);
        }
        $this->assertSame(3, DB::table('compartido_registros_auditoria')->where('nombre_contexto', 'LaboratoryRequests')->count());
        $this->assertSame(0, DB::table('laboratorios_solicitudes')->where('estado', 'AUTORIZADO')->count());
    }

    public function test_quote_is_informational_and_submission_rechecks_current_availability(): void
    {
        $input = $this->input();
        $quote = array_intersect_key($input, array_flip(['laboratoryId', 'startAt', 'endAt']));
        $this->postJson(self::ROOT.'/availability-quotes', $quote)->assertUnauthorized();
        $this->login(User::factory()->create());
        $this->postJson(self::ROOT.'/availability-quotes', $quote)->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.reservation', false);
        $this->postJson(self::ROOT, $input, $this->headers())->assertForbidden();
        $this->assertDatabaseCount('laboratorios_solicitudes', 0);
        $this->login($this->teacher);
        $id = $this->postJson(self::ROOT, $input, $this->headers())->assertCreated()->json('data.id');
        app(LaboratoryCalendar::class)->publish($this->lab, $this->schedule, $this->slot() + ['active' => false], 1, null, $this->administrator->id, (string) Str::uuid());
        $this->postJson(self::ROOT.'/'.$id.'/submit', [], $this->headers(1))->assertConflict();
        $this->assertDatabaseHas('laboratorios_solicitudes', ['id' => $id, 'estado' => 'BORRADOR', 'version' => 1]);
    }

    public function test_wrong_owner_wrong_type_and_wrong_responsible_are_denied(): void
    {
        $this->login($this->teacher);
        $id = $this->postJson(self::ROOT, $this->input(), $this->headers())->assertCreated()->json('data.id');
        $this->postJson(self::ROOT, $this->input('EXTRAORDINARIA'), $this->headers())->assertForbidden();
        $other = User::factory()->create();
        $this->grant($other, 'DOCENTE_SOLICITANTE');
        $this->login($other);
        $this->getJson(self::ROOT)->assertOk()->assertJsonPath('data.items', []);
        $this->getJson(self::ROOT.'/'.$id)->assertNotFound();
        $this->patchJson(self::ROOT.'/'.$id, $this->input(), $this->headers(1))->assertNotFound();
        $this->postJson(self::ROOT.'/'.$id.'/submit', [], $this->headers(1))->assertNotFound();
        $otherLab = $this->planning('laboratories', ['code' => 'S9-OTHER', 'name' => 'Otro laboratorio', 'capacity' => 25, 'status' => 'ACTIVO',
            'careerId' => null, 'responsibleEmployeeId' => null]);
        $this->login($this->technician);
        $input = $this->input('EXTRAORDINARIA');
        $input['laboratoryId'] = $otherLab;
        $this->postJson(self::ROOT, $input, $this->headers())->assertForbidden();
        $this->postJson(self::ROOT, $this->input(), $this->headers())->assertForbidden();
    }

    public function test_invalid_catalog_time_capacity_and_mixed_details_leave_no_partial_rows(): void
    {
        $this->login($this->teacher);
        $valid = $this->input('PRACTICA');
        foreach ([
            array_replace($valid, ['attendees' => 26]),
            array_replace($valid, ['startAt' => '2026-11-16T06:59:00-06:00']),
            array_replace($valid, ['startAt' => '2026-11-16T07:00:00']),
            array_replace($valid, ['startAt' => '2026-11-16T99:00:00-06:00']),
            array_replace($valid, ['endAt' => '2026-11-17T08:00:00-06:00']),
            array_replace($valid, ['startAt' => '2026-11-12T07:00:00-06:00']),
            array_replace_recursive($valid, ['practice' => ['groupId' => (string) Str::ulid()]]),
            $valid + ['various' => null],
            $valid + ['ownerId' => $this->administrator->id],
        ] as $input) {
            $this->postJson(self::ROOT, $input, $this->headers())->assertUnprocessable();
        }
        $this->assertDatabaseCount('laboratorios_solicitudes', 0);
        $this->assertDatabaseCount('laboratorios_detalles_practica', 0);
    }
}
