<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AcademicPlanning\Application\LaboratoryCalendar;
use App\Modules\AcademicPlanning\Application\PlanningDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SprintFiveCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private string $semester;

    private string $laboratory;

    private const ROOT = '/api/v1/academic-planning/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->administrator = User::factory()->create();
        DB::table('iam_identidad_roles')->insert(['id_identidad' => $this->administrator->id,
            'id_rol' => DB::table('iam_roles')->where('codigo', 'ADMIN_PLANIFICACION')->value('id'),
            'id_identidad_otorgante' => $this->administrator->id, 'otorgado_en' => now()]);
        $this->login($this->administrator);
        $semester = $this->create('semesters', ['code' => '2026-2', 'name' => 'Semestre de ensayo', 'startsOn' => '2026-10-01', 'endsOn' => '2026-11-30']);
        $this->semester = $semester['id'];
        app(PlanningDirectory::class)->execute('semesters', 'activate', $this->semester, [], 1, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid());
        $this->laboratory = $this->create('laboratories', $this->labBody())['id'];
    }

    private function login(User $user): void
    {
        $user = $user->fresh();
        $this->actingAs($user)->withSession(['iam_authenticated_at' => now()->timestamp, 'iam_last_activity' => now()->timestamp, 'iam_authorization_version' => $user->version_autorizacion]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    private function create(string $kind, array $data): array
    {
        return app(PlanningDirectory::class)->execute($kind, 'create', null, $data, null, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid());
    }

    /** @return array<string, mixed> */
    private function labBody(string $status = 'ACTIVO', string $code = 'LAB'): array
    {
        return ['code' => $code, 'name' => 'Laboratorio de ensayo', 'capacity' => 20, 'status' => $status, 'careerId' => null, 'responsibleEmployeeId' => null];
    }

    /** @return array<string, mixed> */
    private function slot(string $start = '07:00', string $end = '10:00'): array
    {
        return ['semesterId' => $this->semester, 'weekday' => 1, 'startsAtLocal' => $start, 'endsAtLocal' => $end];
    }

    private function path(?string $laboratory = null): string
    {
        return self::ROOT.'laboratories/'.($laboratory ?? $this->laboratory).'/schedules';
    }

    private function availabilityPath(string $start = '2026-10-19T13:00:00Z', string $end = '2026-10-19T16:00:00Z'): string
    {
        return self::ROOT.'laboratories/'.$this->laboratory.'/availability?'.http_build_query(['startAt' => $start, 'endAt' => $end]);
    }

    public function test_active_readers_consult_but_cannot_publish_and_anonymous_is_rejected(): void
    {
        $this->login(User::factory()->create());
        $this->getJson($this->path().'?semesterId='.$this->semester)->assertOk()->assertJsonPath('data.items', []);
        $this->getJson($this->availabilityPath())->assertOk()->assertJsonPath('data.reasonCode', 'NO_SCHEDULE')->assertJsonPath('data.reservation', false);
        $this->postJson($this->path(), $this->slot(), ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        Auth::logout();
        $this->getJson($this->availabilityPath())->assertUnauthorized();
        $this->getJson($this->path().'?semesterId='.$this->semester)->assertUnauthorized();
    }

    public function test_publish_is_audited_idempotent_and_rejects_reusing_a_key_for_other_times(): void
    {
        $key = (string) Str::uuid();
        $first = $this->postJson($this->path(), $this->slot(), ['Idempotency-Key' => $key])->assertCreated()->assertHeader('ETag', '"1"')->json();
        $this->postJson($this->path(), $this->slot(), ['Idempotency-Key' => $key])->assertCreated()->assertExactJson($first);
        $this->postJson($this->path(), $this->slot('08:00', '09:00'), ['Idempotency-Key' => $key])->assertConflict();
        $this->assertDatabaseCount('planificacion_horarios', 1);
        $this->assertSame(1, DB::table('compartido_registros_auditoria')->where('tipo_sujeto', 'schedules')->count());
        $this->getJson($this->availabilityPath())->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.basis', 'PLANNING_CALENDAR');
    }

    public function test_overlap_is_rejected_but_adjacent_slots_are_merged(): void
    {
        $this->postJson($this->path(), $this->slot('07:00', '08:00'), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->postJson($this->path(), $this->slot('07:30', '08:30'), ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->postJson($this->path(), $this->slot('08:00', '10:00'), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->getJson($this->availabilityPath())->assertOk()->assertJsonPath('data.available', true)->assertJsonCount(1, 'data.intervals');
        $this->assertDatabaseCount('planificacion_horarios', 2);
    }

    public function test_full_update_with_version_withdraws_and_preserves_history(): void
    {
        $slot = $this->postJson($this->path(), $this->slot(), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $body = $this->slot() + ['active' => false];
        $this->patchJson($this->path().'/'.$slot['id'], $body)->assertUnprocessable();
        $this->patchJson($this->path().'/'.$slot['id'], $body, ['If-Match' => '"1"'])->assertOk()->assertHeader('ETag', '"2"')->assertJsonPath('data.active', false);
        $this->patchJson($this->path().'/'.$slot['id'], $body, ['If-Match' => '"1"'])->assertConflict();
        $this->getJson($this->availabilityPath())->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reasonCode', 'NO_SCHEDULE');
        $this->assertDatabaseHas('planificacion_horarios', ['id' => $slot['id'], 'version' => 2, 'activo' => false]);
    }

    public function test_reactivation_is_rechecked_for_conflicts(): void
    {
        $slot = $this->postJson($this->path(), $this->slot(), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->patchJson($this->path().'/'.$slot['id'], $this->slot() + ['active' => false], ['If-Match' => '"1"'])->assertOk();
        $this->postJson($this->path(), $this->slot('08:00', '09:00'), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->patchJson($this->path().'/'.$slot['id'], $this->slot() + ['active' => true], ['If-Match' => '"2"'])->assertConflict();
        $this->assertDatabaseHas('planificacion_horarios', ['id' => $slot['id'], 'version' => 2, 'activo' => false]);
    }

    public function test_update_is_bound_to_the_laboratory_in_the_route(): void
    {
        $slot = $this->postJson($this->path(), $this->slot(), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $other = $this->create('laboratories', $this->labBody(code: 'OTHER'))['id'];
        $this->patchJson($this->path($other).'/'.$slot['id'], $this->slot() + ['active' => false], ['If-Match' => '"1"'])->assertNotFound();
        $this->assertDatabaseHas('planificacion_horarios', ['id' => $slot['id'], 'version' => 1, 'activo' => true]);
    }

    public function test_holidays_apply_globally_or_to_the_selected_lab_with_exact_exclusive_bounds(): void
    {
        $this->postJson($this->path(), $this->slot(), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $type = $this->create('holiday-types', ['code' => 'PAUSA', 'name' => 'Pausa', 'active' => true])['id'];
        $other = $this->create('laboratories', $this->labBody(code: 'OTHER'))['id'];
        foreach ([[null, '13:30', '14:00', true], [$this->laboratory, '14:00', '14:30', true], [$other, '15:00', '16:00', true], [null, '15:00', '16:00', false]] as [$lab, $start, $end, $active]) {
            $this->create('holidays', ['name' => 'Pausa', 'holidayTypeId' => $type, 'laboratoryId' => $lab, 'startsAt' => '2026-10-19T'.$start.':00Z', 'endsAt' => '2026-10-19T'.$end.':00Z', 'active' => $active]);
        }
        $this->getJson($this->availabilityPath())->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.intervals', [
            ['startAt' => '2026-10-19T13:00:00.000000Z', 'endAt' => '2026-10-19T13:30:00.000000Z'],
            ['startAt' => '2026-10-19T14:30:00.000000Z', 'endAt' => '2026-10-19T16:00:00.000000Z'],
        ]);
    }

    public function test_closed_semester_keeps_rows_but_stops_availability_and_mutation(): void
    {
        $slot = $this->postJson($this->path(), $this->slot(), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        app(PlanningDirectory::class)->execute('semesters', 'close', $this->semester, [], 2, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid());
        $this->getJson($this->availabilityPath())->assertOk()->assertJsonPath('data.reasonCode', 'NO_ACTIVE_SEMESTER')->assertJsonPath('data.available', false);
        $this->patchJson($this->path().'/'.$slot['id'], $this->slot() + ['active' => false], ['If-Match' => '"1"'])->assertConflict();
        $this->assertDatabaseHas('planificacion_horarios', ['id' => $slot['id'], 'version' => 1]);
    }

    public function test_maintenance_prevents_availability_and_archiving_requires_withdrawing_active_slots(): void
    {
        $this->postJson($this->path(), $this->slot(), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->patchJson(self::ROOT.'laboratories/'.$this->laboratory, $this->labBody('ARCHIVADO'), ['If-Match' => '"1"'])->assertConflict();
        $this->patchJson(self::ROOT.'laboratories/'.$this->laboratory, $this->labBody('MANTENIMIENTO'), ['If-Match' => '"1"'])->assertOk();
        $this->getJson($this->availabilityPath())->assertOk()->assertJsonPath('data.reasonCode', 'LABORATORY_UNAVAILABLE');
        $this->postJson($this->path(), $this->slot(), ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
    }

    public function test_list_cursor_is_bound_to_lab_semester_and_page_size(): void
    {
        foreach ([['07:00', '08:00'], ['09:00', '10:00']] as [$start, $end]) {
            $this->postJson($this->path(), $this->slot($start, $end), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        }
        $query = '?semesterId='.$this->semester.'&pageSize=1';
        $cursor = $this->getJson($this->path().$query)->assertOk()->assertJsonCount(1, 'data.items')->json('data.nextCursor');
        $this->getJson($this->path().$query.'&cursor='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.nextCursor', null);
        $this->getJson($this->path().'?semesterId='.$this->semester.'&pageSize=2&cursor='.urlencode($cursor))->assertUnprocessable();
        $this->getJson($this->path().'?semesterId='.$this->semester.'&pageSize=101')->assertUnprocessable();
    }

    public function test_invalid_fields_headers_and_operating_times_never_write(): void
    {
        $this->postJson($this->path(), $this->slot())->assertUnprocessable();
        $this->postJson($this->path(), $this->slot() + ['active' => false], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($this->path(), $this->slot('06:59', '10:00'), ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($this->path(), $this->slot() + ['actorId' => $this->administrator->id], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson($this->availabilityPath().'&unexpected=1')->assertUnprocessable();
        $this->getJson($this->availabilityPath('2026-10-01T00:00:00Z', '2026-12-01T00:00:00Z'))->assertUnprocessable();
        $this->assertDatabaseCount('planificacion_horarios', 0);
    }

    public function test_replay_reauthorizes_actor_and_expired_keys_conflict(): void
    {
        $key = (string) Str::uuid();
        $this->postJson($this->path(), $this->slot(), ['Idempotency-Key' => $key])->assertCreated();
        DB::table('planificacion_claves_idempotencia')->update(['expira_en' => now()->subMinute()]);
        $this->postJson($this->path(), $this->slot(), ['Idempotency-Key' => $key])->assertConflict();
        DB::table('iam_identidad_roles')->where('id_identidad', $this->administrator->id)->delete();
        try {
            app(LaboratoryCalendar::class)->publish($this->laboratory, null, $this->slot(), null, $key, $this->administrator->id, (string) Str::uuid());
            $this->fail('Replay bypassed revoked authorization.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_availability_returns_no_personal_data_and_disables_caching(): void
    {
        $response = $this->getJson($this->availabilityPath())->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertEqualsCanonicalizing(['laboratoryId', 'semesterId', 'timeZone', 'startAt', 'endAt', 'available', 'reservation', 'basis', 'intervals', 'reasonCode'], array_keys($response->json('data')));
    }
}
