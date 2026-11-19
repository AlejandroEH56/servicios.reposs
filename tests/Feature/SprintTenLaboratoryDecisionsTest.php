<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AcademicPlanning\Application\LaboratoryCalendar;
use App\Modules\AcademicPlanning\Application\PlanningDirectory;
use App\Modules\LaboratoryRequests\Infrastructure\Documents\ReceiptRenderer;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Shared\Application\Ports\VirusScanner;
use App\Modules\Shared\Infrastructure\Outbox\OutboxProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SprintTenLaboratoryDecisionsTest extends TestCase
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
        Storage::fake('local');
        $this->app->instance(VirusScanner::class, new class implements VirusScanner
        {
            public function isClean(string $path): bool
            {
                return true;
            }
        });
        $this->travelTo(Carbon::parse('2026-11-13T16:00:00Z'));
        $this->administrator = User::factory()->create();
        $this->teacher = User::factory()->create();
        $this->technician = User::factory()->create();
        foreach ([[$this->administrator, 'ADMIN_PLANIFICACION'], [$this->administrator, 'ADMIN_ORGANIZACION'],
            [$this->administrator, 'ADMIN_LABORATORIOS'], [$this->teacher, 'DOCENTE_SOLICITANTE'], [$this->technician, 'LABORATORISTA']] as [$identity, $role]) {
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

    private function pending(?array $input = null, ?User $user = null): array
    {
        $this->login($user ?? $this->teacher);
        $draft = $this->postJson(self::ROOT, $input ?? $this->input(), $this->headers())->assertCreated()->json('data');

        return $this->postJson(self::ROOT.'/'.$draft['id'].'/submit', [], $this->headers(1))->assertOk()->json('data');
    }

    public function test_only_authorization_reserves_and_cancel_releases_the_interval(): void
    {
        $first = $this->pending();
        $second = $this->pending();
        $this->login($this->administrator);
        $path = self::ROOT.'/'.$first['id'];
        $headers = $this->headers(2);
        $body = ['decision' => 'AUTORIZADO', 'reason' => 'Capacidad y horario comprobados'];
        $response = $this->postJson($path.'/decisions', $body, $headers)->assertOk()->assertHeader('ETag', '"3"')->assertJsonPath('data.status', 'AUTORIZADO')->json();
        $this->postJson($path.'/decisions', $body, $headers)->assertOk()->assertExactJson($response);
        $this->getJson($path)->assertConflict();
        $this->getJson(self::ROOT.'/'.$first['id'].'/workflow')->assertOk()->assertJsonPath('data.status', 'AUTORIZADO');
        $this->postJson($path.'/decisions', ['decision' => 'RECHAZADO', 'reason' => 'Cambio de motivo'], $headers)->assertConflict();
        $this->postJson(self::ROOT.'/'.$second['id'].'/decisions', $body, $this->headers(2))->assertConflict();
        $this->assertDatabaseCount('laboratorios_decisiones_solicitud', 1);
        $this->assertSame(1, DB::table('compartido_mensajes_salida')->where('nombre_contexto', 'LaboratoryRequests')->count());
        $range = ['laboratoryId' => $this->lab, 'startAt' => $first['startAt'], 'endAt' => $first['endAt']];
        $this->postJson(self::ROOT.'/availability-quotes', $range)->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reservation', false)->assertJsonPath('data.intervals', []);
        $calendar = $this->getJson(self::ROOT.'/calendar?'.http_build_query($range))->assertOk()->json('data.items');
        $this->assertSame([['startAt' => $first['startAt'], 'endAt' => $first['endAt'], 'status' => 'OCUPADO']], $calendar);
        $this->login($this->teacher);
        $cancelHeaders = $this->headers(3);
        $cancelled = $this->postJson($path.'/cancel', ['reason' => 'Actividad pospuesta'], $cancelHeaders)->assertOk()->assertJsonPath('data.status', 'CANCELADO')->json();
        $this->postJson($path.'/cancel', ['reason' => 'Actividad pospuesta'], $cancelHeaders)->assertOk()->assertExactJson($cancelled);
        $this->getJson($path.'/decisions')->assertOk()->assertJsonCount(2, 'data.items');
        $this->postJson(self::ROOT.'/availability-quotes', $range)->assertOk()->assertJsonPath('data.available', true);
        $this->getJson(self::ROOT.'/calendar?'.http_build_query($range))->assertOk()->assertJsonPath('data.items', []);
        $this->login($this->administrator);
        $this->postJson(self::ROOT.'/'.$second['id'].'/decisions', $body, $this->headers(2))->assertOk();
    }

    public function test_reviewer_scope_ownership_self_review_and_revocation_are_enforced(): void
    {
        $request = $this->pending();
        $path = self::ROOT.'/'.$request['id'];
        $decision = ['decision' => 'RECHAZADO', 'reason' => 'Motivo de rechazo de ensayo'];
        $this->getJson(self::ROOT.'/review-queue')->assertForbidden();
        $this->postJson($path.'/decisions', $decision, $this->headers(2))->assertForbidden();
        $this->grant($this->teacher, 'ADMIN_LABORATORIOS');
        $this->postJson($path.'/decisions', $decision, $this->headers(2))->assertForbidden();
        $this->login($this->technician);
        $this->getJson(self::ROOT.'/review-queue')->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson($path)->assertOk();
        $this->postJson($path.'/decisions', $decision, $this->headers(2))->assertOk()->assertJsonPath('data.status', 'RECHAZADO');
        $this->postJson($path.'/cancel', ['reason' => 'No se permite sobre rechazado'], $this->headers(3))->assertConflict();
        $other = User::factory()->create();
        $this->grant($other, 'LABORATORISTA');
        $this->login($other);
        $this->getJson(self::ROOT.'/review-queue')->assertForbidden();
        $this->getJson($path)->assertNotFound();
        $this->postJson($path.'/decisions', $decision, $this->headers(3))->assertNotFound();
        $this->getJson($path.'/decisions')->assertNotFound();
        $this->getJson($path.'/receipt')->assertNotFound();
        $this->getJson($path.'/receipt/file')->assertNotFound();
        $this->login($this->technician);
        DB::table('iam_identidad_roles')->where('id_identidad', $this->technician->id)->delete();
        $this->getJson($path)->assertNotFound();
    }

    public function test_adjacent_intervals_are_allowed_but_overlapping_submission_is_blocked(): void
    {
        $first = $this->pending();
        $this->login($this->administrator);
        $this->postJson(self::ROOT.'/'.$first['id'].'/decisions', ['decision' => 'AUTORIZADO', 'reason' => 'Validada'], $this->headers(2))->assertOk();
        $adjacent = array_replace($this->input(), ['startAt' => '2026-11-16T08:00:00-06:00', 'endAt' => '2026-11-16T09:00:00-06:00']);
        $next = $this->pending($adjacent);
        $this->login($this->administrator);
        $this->postJson(self::ROOT.'/'.$next['id'].'/decisions', ['decision' => 'AUTORIZADO', 'reason' => 'Franja contigua'], $this->headers(2))->assertOk();
        $this->login($this->teacher);
        $overlap = array_replace($this->input(), ['startAt' => '2026-11-16T07:30:00-06:00', 'endAt' => '2026-11-16T08:30:00-06:00']);
        $draft = $this->postJson(self::ROOT, $overlap, $this->headers())->assertCreated()->json('data.id');
        $this->postJson(self::ROOT.'/'.$draft.'/submit', [], $this->headers(1))->assertConflict();
    }

    public function test_stale_version_changed_calendar_and_started_cancel_leave_no_decision(): void
    {
        $request = $this->pending();
        $this->login($this->administrator);
        $path = self::ROOT.'/'.$request['id'];
        $body = ['decision' => 'AUTORIZADO', 'reason' => 'Revisada'];
        $this->postJson($path.'/decisions', $body, $this->headers(1))->assertConflict();
        $this->postJson($path.'/decisions', $body, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($path.'/decisions', $body + ['ownerId' => $this->administrator->id], $this->headers(2))->assertUnprocessable();
        app(LaboratoryCalendar::class)->publish($this->lab, $this->schedule, $this->slot() + ['active' => false], 1, null, $this->administrator->id, (string) Str::uuid());
        $this->postJson($path.'/decisions', $body, $this->headers(2))->assertConflict();
        $this->assertDatabaseCount('laboratorios_decisiones_solicitud', 0);
        $this->travelTo(Carbon::parse('2026-11-16T13:00:00Z'));
        $this->login($this->teacher);
        $this->postJson($path.'/cancel', ['reason' => 'Fuera de plazo'], $this->headers(2))->assertConflict();
        $this->assertDatabaseCount('laboratorios_decisiones_solicitud', 0);
    }

    public function test_outbox_retries_produce_one_receipt_and_one_private_notification_per_decision(): void
    {
        $request = $this->pending();
        $this->login($this->administrator);
        $path = self::ROOT.'/'.$request['id'];
        $this->postJson($path.'/decisions', ['decision' => 'AUTORIZADO', 'reason' => 'Horario autorizado'], $this->headers(2))->assertOk();
        $this->getJson($path.'/receipt')->assertOk()->assertJsonPath('data.status', 'PENDIENTE');
        $processor = app(OutboxProcessor::class);
        $claimed = $processor->claim();
        $this->assertCount(1, $claimed);
        $this->mock(ReceiptRenderer::class)
            ->shouldReceive('pdf')->once()->andThrow(new \RuntimeException('Temporary failure'));
        $processor->deliver($claimed[0]);
        $this->assertDatabaseCount('laboratorios_comprobantes', 0);
        $this->assertDatabaseCount('laboratorios_notificaciones', 0);
        $this->assertDatabaseHas('compartido_mensajes_salida', ['id' => $claimed[0]->id, 'estado' => 'PENDING']);
        $this->app->forgetInstance(ReceiptRenderer::class);
        $this->travel(6)->seconds();
        $claimed = $processor->claim();
        $processor->deliver($claimed[0]);
        $processor->deliver($claimed[0]);
        $this->assertDatabaseCount('laboratorios_comprobantes', 1);
        $this->assertDatabaseCount('laboratorios_notificaciones', 1);
        $this->assertDatabaseHas('compartido_mensajes_salida', ['id' => $claimed[0]->id, 'estado' => 'PUBLISHED']);
        $this->getJson($path.'/receipt')->assertOk()->assertJsonPath('data.status', 'LISTO');
        $this->login($this->teacher);
        $this->getJson(self::ROOT.'/notifications')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.status', 'AUTORIZADO');
        $pdf = $this->get($path.'/receipt/file')->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $fileId = DB::table('laboratorios_comprobantes')->value('id_archivo');
        Storage::disk('local')->put('clean/'.$fileId, 'tampered');
        $this->get($path.'/receipt/file')->assertStatus(503);
        Storage::disk('local')->put('clean/'.$fileId, $pdf);
        $this->postJson($path.'/cancel', ['reason' => 'Actividad cancelada'], $this->headers(3))->assertOk();
        $this->getJson($path.'/receipt')->assertOk()->assertJsonPath('data.requestStatus', 'CANCELADO')->assertJsonPath('data.status', 'PENDIENTE');
        $this->get($path.'/receipt/file')->assertConflict();
        $processor->runOnce();
        $this->getJson($path.'/receipt')->assertOk()->assertJsonPath('data.status', 'LISTO');
        $this->assertDatabaseCount('laboratorios_comprobantes', 2);
        $this->assertDatabaseCount('laboratorios_notificaciones', 2);
        $this->login(User::factory()->create());
        $this->getJson(self::ROOT.'/notifications')->assertOk()->assertJsonPath('data.items', []);
        $this->getJson($path.'/receipt')->assertNotFound();
    }
}
