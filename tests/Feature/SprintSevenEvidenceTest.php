<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Residencies\Application\EvidenceWorkflow;
use App\Modules\Residencies\Application\ResidencyDirectory;
use App\Modules\Shared\Application\Ports\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SprintSevenEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private User $residentUser;

    private User $reviewer;

    private string $resident;

    private string $program;

    private \stdClass $scanControl;

    /** @var array<string, mixed> */
    private array $definition;

    private const ROOT = '/api/v1/residencies/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->scanner();
        $this->administrator = User::factory()->create();
        $this->grant($this->administrator, 'ADMIN_RESIDENCIAS');
        $this->residentUser = User::factory()->create();
        $this->grant($this->residentUser, 'RESIDENTE');
        $this->reviewer = User::factory()->create();
        $this->grant($this->reviewer, 'DRPSS_REVISOR');
        $service = app(ResidencyDirectory::class);
        $create = fn (string $kind, array $body): array => $service->execute($kind, null, null, $body, null, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid());
        $mode = $create('modalities', ['code' => 'S7', 'name' => 'Modalidad de ensayo', 'active' => true]);
        $this->program = $create('programs', ['code' => 'S7', 'name' => 'Programa de ensayo', 'modalityId' => $mode['id'], 'active' => true])['id'];
        $this->resident = $create('residents', ['identityId' => $this->residentUser->id, 'controlNumber' => '99009901', 'programId' => $this->program, 'givenNames' => 'Persona', 'firstSurname' => 'Piloto'])['id'];
        $this->definition = app(EvidenceWorkflow::class)->execute('define', null, $this->definitionBody(), null, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid());
        $this->login($this->residentUser);
    }

    private function grant(User $user, string $role): void
    {
        DB::table('iam_identidad_roles')->insert(['id_identidad' => $user->id, 'id_rol' => DB::table('iam_roles')->where('codigo', $role)->value('id'), 'id_identidad_otorgante' => $user->id, 'otorgado_en' => now()]);
    }

    private function login(User $user): void
    {
        $user = $user->fresh();
        $this->actingAs($user)->withSession(['iam_authenticated_at' => now()->timestamp, 'iam_last_activity' => now()->timestamp, 'iam_authorization_version' => $user->version_autorizacion]);
    }

    private function scanner(bool $clean = true, bool $available = true): void
    {
        if (! isset($this->scanControl)) {
            $this->scanControl = (object) ['clean' => $clean, 'available' => $available];
            $this->app->instance(VirusScanner::class, new class($this->scanControl) implements VirusScanner
            {
                public function __construct(private \stdClass $control) {}

                public function isClean(string $path): bool
                {
                    if (! $this->control->available) {
                        throw new RuntimeException('private-scanner-detail');
                    }

                    return $this->control->clean;
                }
            });
        }
        $this->scanControl->clean = $clean;
        $this->scanControl->available = $available;
    }

    /** @return array<string, mixed> */
    private function definitionBody(): array
    {
        return ['code' => 'PROOF', 'name' => 'Constancia de ensayo', 'programId' => $this->program, 'stage' => 'PRERREQUISITO', 'scope' => 'RESIDENTE',
            'required' => true, 'displayOrder' => 1, 'validFrom' => null, 'validUntil' => null, 'active' => true, 'allowedMimes' => ['text/plain', 'application/pdf'], 'maxBytes' => 1024 * 1024];
    }

    /** @return array<string, string> */
    private function headers(?string $key = null): array
    {
        return ['Idempotency-Key' => $key ?? (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    private function stage(string $content = 'Harmless documentary evidence.', ?string $key = null, ?User $actor = null): array
    {
        $actor ??= $this->residentUser;
        $file = UploadedFile::fake()->createWithContent('proof.txt', $content);

        return app(EvidenceWorkflow::class)->stage($file->getPathname(), 'proof.txt', ['residentId' => $this->resident, 'requirementId' => $this->definition['id']], $key ?? (string) Str::uuid(), $actor->id, (string) Str::uuid());
    }

    /** @param array<string, mixed> $stage
     * @return array<string, mixed> */
    private function submission(array $stage, int $previous = 0): array
    {
        return ['residentId' => $this->resident, 'projectId' => null, 'requirementId' => $this->definition['id'], 'stagedFileId' => $stage['id'], 'expectedSubmissionVersion' => $previous];
    }

    /** @return array<string, mixed> */
    private function submit(): array
    {
        return $this->postJson(self::ROOT.'evidence', $this->submission($this->stage()), $this->headers())->assertCreated()->json('data');
    }

    public function test_resident_submits_private_file_and_owner_or_reviewer_can_download_with_integrity(): void
    {
        $stage = $this->stage();
        $body = $this->submission($stage);
        $key = (string) Str::uuid();
        $evidence = $this->postJson(self::ROOT.'evidence', $body, $this->headers($key))->assertCreated()->assertJsonPath('data.submissionVersion', 1)->assertJsonPath('data.status', 'ENVIADO')->json('data');
        $this->postJson(self::ROOT.'evidence', $body, $this->headers($key))->assertCreated()->assertJsonPath('data.id', $evidence['id']);
        $this->assertDatabaseCount('residencias_entregas_evidencia', 1);
        $this->get(self::ROOT.'evidence/'.$evidence['id'].'/download')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('Harmless documentary evidence.');
        $this->getJson(self::ROOT.'files/'.$this->resident.'/requirements')->assertOk()->assertJsonPath('data.items.0.latestEvidence.id', $evidence['id']);
        $this->login($this->reviewer);
        $this->getJson(self::ROOT.'review-queue')->assertOk()->assertJsonPath('data.items.0.id', $evidence['id']);
        $this->get(self::ROOT.'evidence/'.$evidence['id'].'/download')->assertOk();
        $file = DB::table('residencias_entregas_evidencia')->where('id', $evidence['id'])->value('id_archivo');
        Storage::disk('local')->put('clean/'.$file, 'tampered');
        $this->get(self::ROOT.'evidence/'.$evidence['id'].'/download')->assertStatus(503)->assertDontSee('tampered');
        $this->postJson(self::ROOT.'evidence/'.$evidence['id'].'/reviews', ['decision' => 'APROBADO', 'expectedReviewNumber' => 1], $this->headers())->assertStatus(503);
        $this->assertDatabaseCount('residencias_revisiones_evidencia', 0);
    }

    public function test_rejection_replacement_and_approval_preserve_file_versions_and_reviews(): void
    {
        $first = $this->submit();
        $this->login($this->reviewer);
        $key = (string) Str::uuid();
        $body = ['decision' => 'CAMBIOS_SOLICITADOS', 'observations' => 'Corrección de ensayo', 'expectedReviewNumber' => 1];
        $this->postJson(self::ROOT.'evidence/'.$first['id'].'/reviews', $body, $this->headers($key))->assertCreated()->assertJsonPath('data.status', 'RECHAZADO');
        $this->postJson(self::ROOT.'evidence/'.$first['id'].'/reviews', $body, $this->headers($key))->assertCreated();
        $this->postJson(self::ROOT.'evidence/'.$first['id'].'/reviews', $body, $this->headers())->assertConflict();
        $this->login($this->residentUser);
        $stage = $this->stage('Corrected documentary evidence.');
        $this->postJson(self::ROOT.'evidence', $this->submission($stage), $this->headers())->assertConflict();
        $second = $this->postJson(self::ROOT.'evidence', $this->submission($stage, 1), $this->headers())->assertCreated()->assertJsonPath('data.submissionVersion', 2)->json('data');
        $this->getJson(self::ROOT.'evidence/'.$first['id'].'/history')->assertOk()->assertJsonPath('data.items.0.status', 'REEMPLAZADO')->assertJsonPath('data.items.0.reviews.0.decision', 'CAMBIOS_SOLICITADOS')->assertJsonPath('data.items.1.status', 'ENVIADO');
        $this->login($this->reviewer);
        $this->postJson(self::ROOT.'evidence/'.$second['id'].'/reviews', ['decision' => 'APROBADO', 'grade' => 95.50, 'expectedReviewNumber' => 1], $this->headers())->assertCreated()->assertJsonPath('data.status', 'APROBADO');
        $this->login($this->residentUser);
        $this->postJson(self::ROOT.'evidence', $this->submission($this->stage('Another version.'), 2), $this->headers())->assertConflict();
        $this->assertDatabaseCount('residencias_revisiones_evidencia', 2);
        $this->assertDatabaseCount('residencias_entregas_evidencia', 2);
    }

    public function test_scope_and_staged_file_ownership_are_enforced(): void
    {
        $stage = $this->stage(actor: $this->administrator);
        $this->postJson(self::ROOT.'evidence', $this->submission($stage), $this->headers())->assertForbidden();
        $evidence = $this->submit();
        $other = User::factory()->create();
        $this->grant($other, 'RESIDENTE');
        $this->login($other);
        foreach (['files/'.$this->resident.'/requirements', 'evidence/'.$evidence['id'], 'evidence/'.$evidence['id'].'/history', 'evidence/'.$evidence['id'].'/download'] as $path) {
            $this->getJson(self::ROOT.$path)->assertForbidden();
        }
        $this->postJson(self::ROOT.'evidence', $this->submission($this->stage()), $this->headers())->assertForbidden();
        $this->getJson(self::ROOT.'review-queue')->assertForbidden();
        $this->getJson(self::ROOT.'requirements')->assertForbidden();
    }

    public function test_reviewers_cannot_review_their_resident_identity_or_own_upload(): void
    {
        $evidence = $this->submit();
        $this->grant($this->residentUser, 'DRPSS_REVISOR');
        $this->login($this->residentUser);
        $body = ['decision' => 'APROBADO', 'expectedReviewNumber' => 1];
        $this->postJson(self::ROOT.'evidence/'.$evidence['id'].'/reviews', $body, $this->headers())->assertForbidden();
        $this->getJson(self::ROOT.'review-queue')->assertOk()->assertJsonPath('data.items', []);
        $this->login($this->administrator);
        $stage = $this->stage('Administrative upload.', actor: $this->administrator);
        $own = $this->postJson(self::ROOT.'evidence', $this->submission($stage, 1), $this->headers())->assertCreated()->json('data');
        $this->postJson(self::ROOT.'evidence/'.$own['id'].'/reviews', $body, $this->headers())->assertForbidden();
        $this->assertDatabaseCount('residencias_revisiones_evidencia', 0);
    }

    public function test_staging_preserves_retries_and_tracks_failed_or_infected_scans_without_submission(): void
    {
        $key = (string) Str::uuid();
        $first = $this->stage(key: $key);
        $again = $this->stage(key: $key);
        $this->assertSame($first, $again);
        $this->assertDatabaseCount('residencias_archivos_preparados', 1);
        $this->assertDatabaseCount('compartido_archivos_almacenados', 1);
        DB::table('residencias_archivos_preparados')->where('id', $first['id'])->update(['expira_en' => now()->subMinute()]);
        $this->postJson(self::ROOT.'evidence', $this->submission($first), $this->headers())->assertConflict();
        $this->scanner(clean: false);
        $payload = ['purpose' => 'RESIDENCY_EVIDENCE', 'residentId' => $this->resident, 'requirementId' => $this->definition['id'], 'file' => UploadedFile::fake()->createWithContent('infected.txt', 'Harmless simulated infected fixture.')];
        $this->post(self::ROOT.'files/staged', $payload, $this->headers() + ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseHas('compartido_archivos_almacenados', ['estado_escaneo' => 'INFECTADO']);
        $this->scanner(available: false);
        $payload['file'] = UploadedFile::fake()->createWithContent('failed.txt', 'Harmless scanner outage fixture.');
        $this->post(self::ROOT.'files/staged', $payload, $this->headers() + ['Accept' => 'application/json'])->assertStatus(503)->assertDontSee('private-scanner-detail');
        $this->assertDatabaseCount('residencias_entregas_evidencia', 0);
        $this->assertSame(2, DB::table('residencias_archivos_preparados')->where('estado', 'FALLIDO')->count());
    }

    public function test_project_evidence_is_bound_to_its_resident_scope_and_active_parent(): void
    {
        $directory = app(ResidencyDirectory::class);
        $create = fn (string $kind, array $body): array => $directory->execute($kind, null, null, $body, null, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid());
        $periodBody = ['code' => 'S7-PROJECT', 'name' => 'Periodo de ensayo', 'startsOn' => '2026-11-01', 'endsOn' => '2027-03-31', 'durationMonths' => 5, 'status' => 'ACTIVO'];
        $period = $create('periods', $periodBody);
        $company = $create('companies', ['name' => 'Empresa de ensayo', 'rfc' => 'XAXX010101000', 'status' => 'ACTIVO']);
        $body = ['residentId' => $this->resident, 'periodId' => $period['id'], 'companyId' => $company['id'], 'programId' => $this->program, 'name' => 'Proyecto de ensayo', 'vacancies' => 1, 'startsOn' => '2026-11-01', 'endsOn' => '2027-03-31'];
        $project = $create('projects', $body);
        $otherUser = User::factory()->create();
        $other = $create('residents', ['identityId' => $otherUser->id, 'controlNumber' => '99009902', 'programId' => $this->program, 'givenNames' => 'Otra', 'firstSurname' => 'Persona']);
        $foreignProject = $create('projects', array_replace($body, ['residentId' => $other['id']]));
        $requirement = app(EvidenceWorkflow::class)->execute('define', null, array_replace($this->definitionBody(), ['code' => 'PROJECT', 'scope' => 'PROYECTO', 'stage' => 'REQUISITO']), null, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid());
        $payload = ['purpose' => 'RESIDENCY_EVIDENCE', 'residentId' => $this->resident, 'requirementId' => $requirement['id']];
        $file = fn (): UploadedFile => UploadedFile::fake()->createWithContent('project.txt', 'Harmless project documentary fixture.');
        $this->post(self::ROOT.'files/staged', $payload + ['file' => $file()], $this->headers() + ['Accept' => 'application/json'])->assertConflict();
        $this->post(self::ROOT.'files/staged', $payload + ['projectId' => $foreignProject['id'], 'file' => $file()], $this->headers() + ['Accept' => 'application/json'])->assertNotFound();
        $stage = $this->post(self::ROOT.'files/staged', $payload + ['projectId' => $project['id'], 'file' => $file()], $this->headers() + ['Accept' => 'application/json'])->assertCreated()->json('data');
        $submission = ['residentId' => $this->resident, 'requirementId' => $requirement['id'], 'projectId' => $project['id'], 'stagedFileId' => $stage['id'], 'expectedSubmissionVersion' => 0];
        $evidence = $this->postJson(self::ROOT.'evidence', $submission, $this->headers())->assertCreated()->json('data');
        $this->getJson(self::ROOT.'files/'.$this->resident.'/requirements?projectId='.$project['id'])->assertOk()->assertJsonPath('data.items.0.latestEvidence.id', $evidence['id']);
        $directory->execute('periods', $period['id'], null, array_replace($periodBody, ['status' => 'CERRADO']), 1, null, $this->administrator->id, (string) Str::uuid());
        $this->post(self::ROOT.'files/staged', $payload + ['projectId' => $project['id'], 'file' => $file()], $this->headers() + ['Accept' => 'application/json'])->assertConflict();
        $this->getJson(self::ROOT.'files/'.$this->resident.'/requirements?projectId='.$project['id'])->assertOk()->assertJsonPath('data.items.0.canSubmit', false);
        $this->get(self::ROOT.'evidence/'.$evidence['id'].'/download')->assertOk();
    }

    public function test_documentary_history_prevents_rebinding_the_resident_program(): void
    {
        $this->submit();
        $this->login($this->administrator);
        $mode = DB::table('residencias_programas_educativos')->where('id', $this->program)->value('id_modalidad');
        $program = app(ResidencyDirectory::class)->execute('programs', null, null, ['code' => 'OTHER', 'name' => 'Otro programa', 'active' => true, 'modalityId' => $mode], null, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid());
        $this->patchJson(self::ROOT.'residents/'.$this->resident, ['programId' => $program['id'], 'givenNames' => 'Persona', 'firstSurname' => 'Piloto'], ['If-Match' => '"1"'])->assertConflict();
        $this->assertDatabaseHas('residencias_residentes', ['id' => $this->resident, 'id_programa' => $this->program]);
    }

    public function test_cursors_preserve_order_and_scope_and_reads_are_batched(): void
    {
        $service = app(EvidenceWorkflow::class);
        for ($i = 0; $i < 30; $i++) {
            $service->execute('define', null, array_replace($this->definitionBody(), ['code' => sprintf('D%02d', $i), 'displayOrder' => $i]), null, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid());
        }
        $path = self::ROOT.'files/'.$this->resident.'/requirements';
        $page = $this->getJson($path.'?pageSize=1')->assertOk()->assertJsonPath('data.items.0.code', 'D00')->json('data');
        $cursor = urlencode($page['nextCursor']);
        $this->getJson($path.'?pageSize=1&cursor='.$cursor)->assertOk()->assertJsonPath('data.items.0.code', 'PROOF');
        $this->getJson($path.'?pageSize=2&cursor='.$cursor)->assertUnprocessable();
        $this->getJson($path.'?pageSize=1&requirementId='.$this->definition['id'])->assertOk()->assertJsonPath('data.items.0.id', $this->definition['id']);
        $this->login($this->reviewer);
        $this->getJson($path.'?pageSize=1&cursor='.$cursor)->assertUnprocessable();
        DB::connection()->enableQueryLog();
        try {
            DB::connection()->flushQueryLog();
            $result = $service->read('requirements', $this->resident, ['pageSize' => 100], $this->residentUser->id);
            $this->assertCount(31, $result['items']);
            $this->assertLessThanOrEqual(9, count(DB::connection()->getQueryLog()));
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }
    }

    public function test_upload_validation_and_stale_requirement_preparation_fail_closed(): void
    {
        $stage = $this->stage();
        $this->login($this->administrator);
        $this->patchJson(self::ROOT.'requirements/'.$this->definition['id'], array_replace($this->definitionBody(), ['name' => 'Nombre actualizado']), ['If-Match' => '"1"'])->assertOk();
        $this->login($this->residentUser);
        $this->postJson(self::ROOT.'evidence', $this->submission($stage), $this->headers())->assertConflict();
        $payload = ['purpose' => 'RESIDENCY_EVIDENCE', 'residentId' => $this->resident, 'requirementId' => $this->definition['id']];
        $this->post(self::ROOT.'files/staged', $payload + ['file' => UploadedFile::fake()->createWithContent('bad.svg', '<svg onload="alert(1)"/>')], $this->headers() + ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('invalidFields.0', 'file');
        $this->post(self::ROOT.'files/staged', $payload + ['file' => UploadedFile::fake()->create('large.txt', 10241, 'text/plain')], $this->headers() + ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post(self::ROOT.'files/staged', array_replace($payload, ['purpose' => 'NOTICE_IMAGE', 'file' => UploadedFile::fake()->createWithContent('proof.txt', 'Harmless fixture.')]), $this->headers() + ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('residencias_entregas_evidencia', 0);
        $this->assertDatabaseCount('compartido_archivos_almacenados', 1);
    }

    public function test_idempotent_replays_cannot_bypass_a_revoked_permission(): void
    {
        $key = (string) Str::uuid();
        $body = $this->submission($this->stage());
        $this->postJson(self::ROOT.'evidence', $body, $this->headers($key))->assertCreated();
        DB::table('iam_identidad_roles')->where('id_identidad', $this->residentUser->id)->delete();
        $this->postJson(self::ROOT.'evidence', $body, $this->headers($key))->assertForbidden();
        $this->assertDatabaseCount('residencias_entregas_evidencia', 1);
    }

    public function test_definition_concurrency_dto_validation_and_snapshot_preservation(): void
    {
        $evidence = $this->submit();
        $this->login($this->administrator);
        $path = self::ROOT.'requirements/'.$this->definition['id'];
        $body = array_replace($this->definitionBody(), ['name' => 'Nombre nuevo']);
        $this->patchJson($path, $body, ['If-Match' => '"1"'])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($path, $body, ['If-Match' => '"1"'])->assertConflict();
        $this->patchJson($path, array_replace($body, ['scope' => 'PROYECTO']), ['If-Match' => '"2"'])->assertConflict();
        $this->getJson(self::ROOT.'evidence/'.$evidence['id'])->assertOk()->assertJsonPath('data.requirementSnapshot.name', 'Constancia de ensayo');
        $this->postJson(self::ROOT.'requirements', $body + ['private-extra' => 'private-value'], $this->headers())->assertUnprocessable()->assertDontSee('private-value');
        $this->login($this->reviewer);
        $this->postJson(self::ROOT.'evidence/'.$evidence['id'].'/reviews', ['decision' => 'RECHAZADO', 'expectedReviewNumber' => 1], $this->headers())->assertUnprocessable()->assertJsonPath('invalidFields.0', 'observations');
    }
}
