<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Residencies\Application\ResidencyDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SprintSixResidenciesTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private User $residentUser;

    private string $program;

    private string $period;

    private string $company;

    private string $resident;

    private const ROOT = '/api/v1/residencies/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->administrator = User::factory()->create();
        $this->grant($this->administrator, 'ADMIN_RESIDENCIAS');
        $this->residentUser = User::factory()->create();
        $this->grant($this->residentUser, 'RESIDENTE');
        $this->login($this->administrator);
        $mode = $this->create('modalities', ['code' => 'PRES', 'name' => 'Modalidad de ensayo', 'active' => true]);
        $this->program = $this->create('programs', ['code' => 'PROG', 'name' => 'Programa de ensayo', 'modalityId' => $mode['id'], 'active' => true])['id'];
        $this->period = $this->create('periods', $this->periodBody())['id'];
        $this->company = $this->create('companies', $this->companyBody())['id'];
        $this->resident = $this->create('residents', $this->profile() + ['identityId' => $this->residentUser->id, 'controlNumber' => '21000001'])['id'];
    }

    private function grant(User $user, string $role): void
    {
        DB::table('iam_identidad_roles')->insert(['id_identidad' => $user->id, 'id_rol' => DB::table('iam_roles')->where('codigo', $role)->value('id'), 'id_identidad_otorgante' => $user->id, 'otorgado_en' => now()]);
    }

    private function login(User $user): void
    {
        $this->actingAs($user->fresh())->withSession(['iam_authenticated_at' => now()->timestamp, 'iam_last_activity' => now()->timestamp, 'iam_authorization_version' => $user->fresh()->version_autorizacion]);
    }

    private function create(string $kind, array $body, ?string $parent = null, ?int $version = null): array
    {
        return app(ResidencyDirectory::class)->execute($kind, null, $parent, $body, $version, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid());
    }

    private function profile(): array
    {
        return ['programId' => $this->program, 'givenNames' => 'Persona', 'firstSurname' => 'Piloto', 'city' => 'Ciudad de ensayo'];
    }

    private function periodBody(string $code = '2026-2', string $start = '2026-08-01', string $end = '2026-12-31'): array
    {
        return ['code' => $code, 'name' => 'Periodo de ensayo', 'startsOn' => $start, 'endsOn' => $end, 'durationMonths' => 5, 'status' => 'ACTIVO'];
    }

    private function companyBody(string $rfc = 'XAXX010101000'): array
    {
        return ['name' => 'Empresa de ensayo', 'rfc' => $rfc, 'status' => 'ACTIVO'];
    }

    private function projectBody(): array
    {
        return ['residentId' => $this->resident, 'periodId' => $this->period, 'companyId' => $this->company, 'programId' => $this->program, 'name' => 'Proyecto de ensayo', 'vacancies' => 1, 'startsOn' => '2026-08-01', 'endsOn' => '2026-12-31'];
    }

    private function headers(?int $version = null, ?string $key = null): array
    {
        return ['Idempotency-Key' => $key ?? (string) Str::uuid()] + ($version === null ? [] : ['If-Match' => '"'.$version.'"']);
    }

    public function test_reads_are_scoped_and_anonymous_access_is_denied(): void
    {
        $this->login($this->residentUser);
        $this->getJson(self::ROOT.'me')->assertOk()->assertJsonPath('data.resident.id', $this->resident)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson(self::ROOT.'residents/'.$this->resident)->assertOk();
        $other = $this->create('residents', $this->profile() + ['identityId' => User::factory()->create()->id, 'controlNumber' => '21000002']);
        $this->getJson(self::ROOT.'residents/'.$other['id'])->assertForbidden();
        $this->getJson(self::ROOT.'residents')->assertForbidden();
        $this->getJson(self::ROOT.'identity-candidates')->assertForbidden();
        $this->getJson(self::ROOT.'companies')->assertOk()->assertJsonMissingPath('data.items.0.email')->assertJsonMissingPath('data.items.0.id_identidad_creadora');
        Auth::logout();
        $this->getJson(self::ROOT.'programs')->assertUnauthorized();
        $this->getJson(self::ROOT.'me')->assertUnauthorized();
    }

    public function test_profile_update_cannot_rebind_identity_control_or_program_and_requires_version(): void
    {
        $modality = DB::table('residencias_programas_educativos')->where('id', $this->program)->value('id_modalidad');
        $otherProgram = $this->create('programs', ['code' => 'OTHER', 'name' => 'Otro programa', 'modalityId' => $modality, 'active' => true]);
        $this->login($this->residentUser);
        $path = self::ROOT.'residents/'.$this->resident;
        $this->patchJson($path, $this->profile(), $this->headers())->assertUnprocessable();
        $this->patchJson($path, $this->profile() + ['identityId' => $this->administrator->id], $this->headers(1))->assertUnprocessable();
        $this->patchJson($path, $this->profile() + ['controlNumber' => '21000002'], $this->headers(1))->assertUnprocessable();
        $this->patchJson($path, array_replace($this->profile(), ['programId' => $otherProgram['id']]), $this->headers(1))->assertForbidden();
        $this->patchJson($path, $this->profile() + ['address' => 'Domicilio de ensayo'], $this->headers(1))->assertOk()->assertHeader('ETag', '"2"');
        $this->patchJson($path, $this->profile(), $this->headers(1))->assertConflict();
        $this->postJson(self::ROOT.'residents', $this->profile() + ['identityId' => $this->residentUser->id, 'controlNumber' => '21000003'], $this->headers())->assertForbidden();
    }

    public function test_administrator_can_enroll_own_active_identity_and_invalid_fields_never_echo_input(): void
    {
        $this->login($this->administrator);
        $body = $this->profile() + ['identityId' => $this->administrator->id, 'controlNumber' => '99009901'];
        $invalid = $this->postJson(self::ROOT.'residents', array_replace($body, [
            'controlNumber' => 'secret-invalid-control', 'curp' => 'private-invalid-curp', 'private-input-key' => 'private-input-value',
        ]), $this->headers());
        $invalid->assertUnprocessable()->assertJsonPath('invalidFields', ['controlNumber', 'curp']);
        foreach (['secret-invalid-control', 'private-invalid-curp', 'private-input-key', 'private-input-value'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $invalid->getContent());
        }
        $this->assertDatabaseMissing('residencias_residentes', ['id_identidad' => $this->administrator->id]);
        $created = $this->postJson(self::ROOT.'residents', $body, $this->headers())
            ->assertCreated()->assertJsonPath('data.identityId', $this->administrator->id)
            ->assertJsonPath('data.controlNumber', '99009901')->json('data');
        $this->getJson(self::ROOT.'me')->assertOk()->assertJsonPath('data.resident.id', $created['id']);
        $this->patchJson(self::ROOT.'residents/'.$created['id'], array_replace($this->profile(), ['city' => 'Perfil editado']), $this->headers(1))
            ->assertOk()->assertJsonPath('data.city', 'Perfil editado');
    }

    public function test_company_rfc_normalizes_rejects_collisions_and_preserves_creator_ownership(): void
    {
        $this->login($this->residentUser);
        $body = $this->companyBody(' xexx010101000 ');
        $key = (string) Str::uuid();
        $first = $this->postJson(self::ROOT.'companies', $body, $this->headers(null, $key))->assertCreated()->assertJsonPath('data.rfc', 'XEXX010101000')->json('data');
        $this->postJson(self::ROOT.'companies', $body, $this->headers(null, $key))->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->postJson(self::ROOT.'companies', $this->companyBody('XEXX010101000'), $this->headers())->assertConflict();
        $this->patchJson(self::ROOT.'companies/'.$this->company, $this->companyBody(), $this->headers(1))->assertForbidden();
        $this->patchJson(self::ROOT.'companies/'.$first['id'], $this->companyBody('XEXX010101000'), $this->headers(1))->assertOk();
        $this->patchJson(self::ROOT.'companies/'.$first['id'], $this->companyBody('ABC010101AB1'), $this->headers(2))->assertConflict();
        $this->postJson(self::ROOT.'companies', $this->companyBody('ÑAA010101AA6'), $this->headers())->assertCreated()->assertJsonPath('data.rfc', 'ÑAA010101AA6');
    }

    public function test_contact_parent_binding_and_directory_do_not_expose_other_people(): void
    {
        $contact = $this->create('contacts', ['givenNames' => 'Contacto', 'firstSurname' => 'Ensayo', 'active' => true, 'email' => 'contact@example.test'], $this->company);
        $this->login($this->residentUser);
        $this->getJson(self::ROOT.'companies/'.$this->company.'/contacts')->assertForbidden();
        $project = $this->postJson(self::ROOT.'projects', $this->projectBody(), $this->headers())->assertCreated()->json('data');
        $this->getJson(self::ROOT.'companies/'.$this->company.'/contacts')->assertOk()->assertJsonPath('data.items.0.id', $contact['id']);
        $this->postJson(self::ROOT.'companies/'.$this->company.'/contacts', ['givenNames' => 'Ajeno', 'firstSurname' => 'Ensayo', 'active' => true], $this->headers())->assertForbidden();
        $other = $this->create('companies', $this->companyBody('XEXX010101000'));
        $this->login($this->administrator);
        $this->patchJson(self::ROOT.'companies/'.$other['id'].'/contacts/'.$contact['id'], ['givenNames' => 'Contacto', 'firstSurname' => 'Ensayo', 'active' => false], $this->headers(1))->assertNotFound();
    }

    public function test_project_is_idempotent_unique_per_period_and_prevents_inclusive_historical_overlap(): void
    {
        $this->login($this->residentUser);
        $key = (string) Str::uuid();
        $body = $this->projectBody();
        $project = $this->postJson(self::ROOT.'projects', $body, $this->headers(null, $key))->assertCreated()->json('data');
        $this->postJson(self::ROOT.'projects', $body, $this->headers(null, $key))->assertCreated()->assertJsonPath('data.id', $project['id']);
        $this->postJson(self::ROOT.'projects', $body, $this->headers())->assertConflict();
        $second = $this->create('periods', $this->periodBody('2027-1', '2026-12-31', '2027-06-30'));
        $this->postJson(self::ROOT.'projects', array_replace($body, ['periodId' => $second['id'], 'startsOn' => '2026-12-31', 'endsOn' => '2027-06-30']), $this->headers())->assertConflict();
        $this->postJson(self::ROOT.'projects', array_replace($body, ['periodId' => $second['id'], 'startsOn' => '2027-01-01', 'endsOn' => '2027-06-30']), $this->headers())->assertCreated();
        $this->assertDatabaseCount('residencias_proyectos', 2);
        $this->assertSame(2, DB::table('compartido_registros_auditoria')->where('nombre_contexto', 'Residencies')->where('tipo_sujeto', 'projects')->count());
    }

    public function test_project_cannot_escape_owner_period_program_or_current_version(): void
    {
        $project = $this->create('projects', $this->projectBody());
        $body = $this->projectBody();
        $modality = DB::table('residencias_programas_educativos')->where('id', $this->program)->value('id_modalidad');
        $otherProgram = $this->create('programs', ['code' => 'OTHER', 'name' => 'Otro programa', 'modalityId' => $modality, 'active' => true]);
        $this->login($this->residentUser);
        $this->postJson(self::ROOT.'projects', array_replace($body, ['residentId' => Str::ulid()->toBase32()]), $this->headers())->assertNotFound();
        $this->patchJson(self::ROOT.'projects/'.$project['id'], array_replace($body, ['startsOn' => '2026-07-31']), $this->headers(1))->assertUnprocessable();
        $this->patchJson(self::ROOT.'projects/'.$project['id'], array_replace($body, ['programId' => $otherProgram['id']]), $this->headers(1))->assertUnprocessable();
        $this->patchJson(self::ROOT.'projects/'.$project['id'], array_replace($body, ['name' => 'Editado']), $this->headers(1))->assertOk()->assertHeader('ETag', '"2"');
        $this->patchJson(self::ROOT.'projects/'.$project['id'], $body, $this->headers(1))->assertConflict();
        $other = User::factory()->create();
        $this->grant($other, 'RESIDENTE');
        $this->login($other);
        $this->getJson(self::ROOT.'projects/'.$project['id'])->assertForbidden();
        $this->patchJson(self::ROOT.'projects/'.$project['id'], $body, $this->headers(2))->assertForbidden();
        $this->getJson(self::ROOT.'projects')->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_internal_adviser_snapshots_come_from_organization_and_stay_immutable(): void
    {
        $this->grant($this->administrator, 'ADMIN_ORGANIZACION');
        $employee = app(OrganizationDirectory::class)->execute('createEmployee', null, ['identityId' => $this->administrator->id, 'givenNames' => 'Asesor', 'firstSurname' => 'Piloto', 'isAdviser' => true], $this->administrator->id, (string) Str::uuid());
        $project = $this->create('projects', $this->projectBody());
        $path = self::ROOT.'projects/'.$project['id'].'/internal-advisers';
        $this->login($this->residentUser);
        $body = ['employeeId' => $employee['id'], 'roleCode' => 'PRINCIPAL', 'startsOn' => '2026-08-01'];
        $key = (string) Str::uuid();
        $this->postJson($path, $body + ['nameSnapshot' => 'Inyectado'], $this->headers(1))->assertUnprocessable();
        $assignment = $this->postJson($path, $body, $this->headers(1, $key))->assertCreated()->assertJsonPath('data.nameSnapshot', 'Asesor Piloto')->assertJsonPath('data.positionSnapshot', null)->assertJsonPath('data.projectVersion', 2)->assertHeader('ETag', '"2"')->json('data');
        $this->postJson($path, $body, $this->headers(1, $key))->assertCreated()->assertJsonPath('data.id', $assignment['id']);
        $this->postJson($path, $body, $this->headers(2))->assertConflict();
        DB::table('organizacion_empleados')->where('id', $employee['id'])->update(['nombres' => 'Nombre posterior']);
        $this->getJson(self::ROOT.'projects/'.$project['id'])->assertOk()->assertJsonPath('data.internalAdvisers.0.nameSnapshot', 'Asesor Piloto');
        $this->patchJson($path.'/'.$assignment['id'], ['endsOn' => '2026-09-30'], $this->headers(2))->assertOk()->assertJsonPath('data.projectVersion', 3);
        $this->postJson($path, array_replace($body, ['startsOn' => '2026-10-01']), $this->headers(3))->assertCreated()->assertJsonPath('data.nameSnapshot', 'Nombre posterior Piloto');
    }

    public function test_external_adviser_must_belong_to_project_company_and_contact_changes_do_not_change_snapshot(): void
    {
        $project = $this->create('projects', $this->projectBody());
        $other = $this->create('companies', $this->companyBody('XEXX010101000'));
        $wrong = $this->create('contacts', ['givenNames' => 'Ajeno', 'firstSurname' => 'Ensayo', 'active' => true], $other['id']);
        $contact = $this->create('contacts', ['givenNames' => 'Contacto', 'firstSurname' => 'Ensayo', 'active' => true, 'position' => 'Asesor externo'], $this->company);
        $this->login($this->residentUser);
        $path = self::ROOT.'projects/'.$project['id'].'/external-advisers';
        $body = ['contactId' => $contact['id'], 'roleCode' => 'PRINCIPAL', 'startsOn' => '2026-08-01'];
        $this->postJson($path, array_replace($body, ['contactId' => $wrong['id']]), $this->headers(1))->assertUnprocessable();
        $assignment = $this->postJson($path, $body, $this->headers(1))->assertCreated()->json('data');
        DB::table('residencias_contactos_empresa')->where('id', $contact['id'])->update(['nombres' => 'Posterior']);
        $this->getJson(self::ROOT.'projects/'.$project['id'])->assertOk()->assertJsonPath('data.externalAdvisers.0.nameSnapshot', 'Contacto Ensayo');
        $otherProject = $this->create('periods', $this->periodBody('OTHER', '2027-01-01', '2027-06-30'));
        $next = $this->create('projects', array_replace($this->projectBody(), ['periodId' => $otherProject['id'], 'startsOn' => '2027-01-01', 'endsOn' => '2027-06-30']));
        $this->patchJson(self::ROOT.'projects/'.$next['id'].'/external-advisers/'.$assignment['id'], ['endsOn' => '2027-02-01'], $this->headers(1))->assertNotFound();
    }

    public function test_catalog_and_period_changes_preserve_existing_project_references(): void
    {
        $this->create('projects', $this->projectBody());
        $this->patchJson(self::ROOT.'periods/'.$this->period, array_replace($this->periodBody(), ['endsOn' => '2026-12-30']), $this->headers(1))->assertConflict();
        $this->patchJson(self::ROOT.'periods/'.$this->period, array_replace($this->periodBody(), ['status' => 'CERRADO']), $this->headers(1))->assertOk();
        $this->login($this->residentUser);
        $this->postJson(self::ROOT.'projects', $this->projectBody(), $this->headers())->assertConflict();
        $this->postJson(self::ROOT.'periods', $this->periodBody('NO'), $this->headers())->assertForbidden();
        $this->getJson(self::ROOT.'periods/'.$this->period)->assertOk()->assertJsonPath('data.partialReports', 3)->assertJsonPath('data.finalReports', 1);
    }

    public function test_cursor_is_bound_to_actor_search_and_collection_and_inputs_are_closed(): void
    {
        $this->create('companies', $this->companyBody('XEXX010101000'));
        $cursor = $this->getJson(self::ROOT.'companies?pageSize=1')->assertOk()->json('data.nextCursor');
        $this->assertNotNull($cursor);
        $this->getJson(self::ROOT.'companies?pageSize=1&cursor='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson(self::ROOT.'programs?pageSize=1&cursor='.urlencode($cursor))->assertUnprocessable();
        $this->login($this->residentUser);
        $this->getJson(self::ROOT.'companies?pageSize=1&cursor='.urlencode($cursor))->assertUnprocessable();
        $this->getJson(self::ROOT.'companies?unknown=1')->assertUnprocessable();
        $this->postJson(self::ROOT.'companies', $this->companyBody('ABC010101AB1') + ['ownerId' => $this->administrator->id], $this->headers())->assertUnprocessable();
        $this->postJson(self::ROOT.'companies', $this->companyBody('ABC010101AB2'), $this->headers())->assertUnprocessable();
        $this->post(self::ROOT.'companies', $this->companyBody(), ['Accept' => 'application/json'])->assertStatus(415);
    }

    public function test_expired_and_revoked_idempotency_replays_are_not_authorized(): void
    {
        $this->login($this->residentUser);
        $key = (string) Str::uuid();
        $body = $this->companyBody('XEXX010101000');
        $this->postJson(self::ROOT.'companies', $body, $this->headers(null, $key))->assertCreated();
        DB::table('residencias_claves_idempotencia')->update(['expira_en' => now()->subSecond()]);
        $this->postJson(self::ROOT.'companies', $body, $this->headers(null, $key))->assertConflict();
        DB::table('iam_identidad_roles')->where('id_identidad', $this->residentUser->id)->delete();
        $this->postJson(self::ROOT.'companies', $body, $this->headers(null, $key))->assertForbidden();
    }

    public function test_company_page_has_a_bounded_query_budget_and_omits_personal_contacts(): void
    {
        $this->create('companies', $this->companyBody('XEXX010101000'));
        $this->create('companies', $this->companyBody('ABC010101AB1'));
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            $page = app(ResidencyDirectory::class)->read('companies', null, null, ['pageSize' => 100], $this->residentUser->id);
            $this->assertCount(3, $page['items']);
            $this->assertLessThanOrEqual(5, count(DB::connection()->getQueryLog()));
            foreach ($page['items'] as $company) {
                $this->assertArrayNotHasKey('email', $company);
                $this->assertArrayNotHasKey('canReadContacts', $company);
            }
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }
    }

    public function test_adviser_directory_batches_profiles_and_excludes_private_identity_data(): void
    {
        $this->grant($this->administrator, 'ADMIN_ORGANIZACION');
        foreach ([$this->administrator, $this->residentUser] as $identity) {
            app(OrganizationDirectory::class)->execute('createEmployee', null, ['identityId' => $identity->id, 'givenNames' => 'Asesor', 'firstSurname' => 'Ensayo', 'isAdviser' => true], $this->administrator->id, (string) Str::uuid());
        }
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            $rows = app(OrganizationDirectory::class)->advisers('Asesor');
            $this->assertCount(2, $rows);
            $this->assertLessThanOrEqual(4, count(DB::connection()->getQueryLog()));
            foreach ($rows as $row) {
                $this->assertArrayNotHasKey('email', $row);
                $this->assertArrayNotHasKey('identityId', $row);
                $this->assertSame([], $row['positions']);
            }
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }
    }

    public function test_long_adviser_names_are_not_truncated_and_principal_intervals_cannot_overlap(): void
    {
        $this->grant($this->administrator, 'ADMIN_ORGANIZACION');
        $names = ['givenNames' => str_repeat('A', 160), 'firstSurname' => str_repeat('B', 120), 'secondSurname' => str_repeat('C', 120)];
        $employee = app(OrganizationDirectory::class)->execute('createEmployee', null, $names + ['identityId' => $this->administrator->id, 'isAdviser' => true], $this->administrator->id, (string) Str::uuid());
        $other = app(OrganizationDirectory::class)->execute('createEmployee', null, ['givenNames' => 'Otro', 'firstSurname' => 'Asesor', 'identityId' => $this->residentUser->id, 'isAdviser' => true], $this->administrator->id, (string) Str::uuid());
        $project = $this->create('projects', $this->projectBody());
        $path = self::ROOT.'projects/'.$project['id'].'/internal-advisers';
        $body = ['employeeId' => $employee['id'], 'roleCode' => 'PRINCIPAL', 'startsOn' => '2026-08-01'];
        $this->postJson($path, $body, $this->headers(1))->assertCreated()->assertJsonPath('data.nameSnapshot', implode(' ', $names));
        $this->postJson($path, array_replace($body, ['employeeId' => $other['id']]), $this->headers(2))->assertConflict();
        $this->postJson($path, array_replace($body, ['employeeId' => $other['id'], 'roleCode' => 'COLABORADOR']), $this->headers(2))->assertCreated()->assertJsonPath('data.projectVersion', 3);
    }

    public function test_inactive_filters_include_blocked_and_planned_records_without_assuming_one_inactive_state(): void
    {
        $company = $this->create('companies', array_replace($this->companyBody('XEXX010101000'), ['status' => 'BLOQUEADO']));
        $period = $this->create('periods', array_replace($this->periodBody('PLAN'), ['status' => 'PLANIFICADO']));
        $this->getJson(self::ROOT.'companies?active=0')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $company['id']);
        $this->getJson(self::ROOT.'periods?active=0')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $period['id']);
    }
}
