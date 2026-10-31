<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Residencies\Application\DocumentWorkflow;
use App\Modules\Residencies\Application\EvidenceWorkflow;
use App\Modules\Residencies\Application\Ports\DocumentRenderer;
use App\Modules\Residencies\Infrastructure\Documents\DompdfRenderer;
use App\Modules\Shared\Application\Ports\VirusScanner;
use App\Modules\Shared\Infrastructure\Outbox\OutboxProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CompletionFixture;
use Tests\TestCase;

class SprintEightCompletionTest extends TestCase
{
    use RefreshDatabase;

    private CompletionFixture $fixture;

    private \stdClass $control;

    private const ROOT = '/api/v1/residencies/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->control = (object) ['clean' => true, 'render' => true];
        $this->app->instance(VirusScanner::class, new class($this->control) implements VirusScanner
        {
            public function __construct(private \stdClass $control) {}

            public function isClean(string $path): bool
            {
                if (isset($this->control->scanCallback)) {
                    $callback = $this->control->scanCallback;
                    $this->control->scanCallback = null;
                    $callback();
                }

                return $this->control->clean;
            }
        });
        $this->app->instance(DocumentRenderer::class, new class($this->control) implements DocumentRenderer
        {
            public function __construct(private \stdClass $control) {}

            public function html(array $snapshot): string
            {
                return app(DompdfRenderer::class)->html($snapshot);
            }

            public function pdf(string $html): string
            {
                if (! $this->control->render) {
                    throw new RuntimeException('private renderer detail');
                }

                return "%PDF-1.4\n% ".hash('sha256', $html)."\n%%EOF";
            }
        });
        $this->fixture = new CompletionFixture;
        $this->login($this->fixture->administrator);
    }

    private function login(User $user): void
    {
        $user = $user->fresh();
        $this->actingAs($user)->withSession(['iam_authenticated_at' => now()->timestamp, 'iam_last_activity' => now()->timestamp, 'iam_authorization_version' => $user->version_autorizacion]);
    }

    /** @return array<string,string> */
    private function headers(?string $key = null): array
    {
        return ['Idempotency-Key' => $key ?? (string) Str::uuid()];
    }

    private function path(string $suffix): string
    {
        return self::ROOT.'projects/'.$this->fixture->project.'/'.$suffix;
    }

    public function test_plan_reads_are_pure_initialization_is_idempotent_and_internal_definitions_are_hidden(): void
    {
        $this->getJson($this->path('report-plan'))->assertOk()->assertJsonPath('data.initialized', false)->assertJsonCount(3, 'data.plannedWindows');
        $this->assertDatabaseCount('residencias_reportes_avance', 0);
        $key = (string) Str::uuid();
        $this->postJson($this->path('report-plan'), [], $this->headers($key))->assertCreated();
        $this->postJson($this->path('report-plan'), [], $this->headers($key))->assertCreated();
        $this->assertDatabaseCount('residencias_reportes_avance', 3);
        $this->getJson(self::ROOT.'requirements')->assertOk()->assertJsonCount(0, 'data.items');
        $id = $this->fixture->plan()['items'][0]['requirementId'];
        $this->getJson(self::ROOT.'requirements/'.$id)->assertNotFound();
        $this->login(User::factory()->create());
        $this->getJson($this->path('report-plan'))->assertForbidden();
        $this->postJson($this->path('report-plan'), [], $this->headers())->assertForbidden();
    }

    public function test_ordinary_evidence_reopens_without_losing_reviews_and_allows_a_new_version(): void
    {
        $workflow = app(EvidenceWorkflow::class);
        $actor = $this->fixture->administrator->id;
        $owner = $this->fixture->residentUser->id;
        $definition = $workflow->execute('define', null, ['code' => 'S8-DOC', 'name' => 'Documento de ensayo', 'programId' => null, 'stage' => 'REQUISITO', 'scope' => 'RESIDENTE', 'required' => false, 'displayOrder' => 0, 'validFrom' => null, 'validUntil' => null, 'active' => true, 'allowedMimes' => ['text/plain'], 'maxBytes' => 1024], null, (string) Str::uuid(), $actor, (string) Str::uuid());
        $file = UploadedFile::fake()->createWithContent('proof.txt', 'Synthetic proof version one');
        $context = ['residentId' => $this->fixture->resident, 'requirementId' => $definition['id']];
        $stage = $workflow->stage($file->getPathname(), 'proof.txt', $context, (string) Str::uuid(), $owner, (string) Str::uuid());
        $delivery = $workflow->execute('submit', null, $context + ['stagedFileId' => $stage['id'], 'expectedSubmissionVersion' => 0], null, (string) Str::uuid(), $owner, (string) Str::uuid());
        $workflow->execute('review', $delivery['id'], ['decision' => 'APROBADO', 'expectedReviewNumber' => 1], null, (string) Str::uuid(), $actor, (string) Str::uuid());
        $path = self::ROOT.'evidence/'.$delivery['id'];
        $this->getJson($path.'/reopen-history')->assertOk()->assertJsonPath('data.canReopen', true);
        $this->fixture->grant($this->fixture->residentUser, 'DRPSS_REVISOR');
        $this->login($this->fixture->residentUser);
        $this->getJson($path.'/reopen-history')->assertOk()->assertJsonPath('data.canReopen', false);
        $this->postJson($path.'/reopen', ['observations' => 'Revisión independiente', 'expectedReviewNumber' => 1], $this->headers())->assertForbidden();
        $this->login($this->fixture->administrator);
        $this->postJson($path.'/reopen', ['observations' => 'Corrección documental de ensayo', 'expectedReviewNumber' => 1], $this->headers())->assertCreated();
        $this->getJson($path.'/reopen-history')->assertOk()->assertJsonPath('data.canReopen', false)->assertJsonCount(1, 'data.items');
        $this->getJson($path.'/history')->assertOk()->assertJsonPath('data.items.0.reviews.0.decision', 'APROBADO');
        $file = UploadedFile::fake()->createWithContent('proof.txt', 'Synthetic proof version two');
        $stage = $workflow->stage($file->getPathname(), 'proof.txt', $context, (string) Str::uuid(), $owner, (string) Str::uuid());
        $replacement = $workflow->execute('submit', null, $context + ['stagedFileId' => $stage['id'], 'expectedSubmissionVersion' => 1], null, (string) Str::uuid(), $owner, (string) Str::uuid());
        $this->assertSame(2, $replacement['submissionVersion']);
        $this->assertDatabaseHas('residencias_entregas_evidencia', ['id' => $delivery['id'], 'estado' => 'REEMPLAZADO']);
        $this->fixture->initializeAndApprove();
        $this->fixture->completeReports();
        $this->fixture->release();
        $workflow->execute('review', $replacement['id'], ['decision' => 'APROBADO', 'expectedReviewNumber' => 1], null, (string) Str::uuid(), $actor, (string) Str::uuid());
        $this->getJson(self::ROOT.'evidence/'.$replacement['id'].'/reopen-history')->assertOk()->assertJsonPath('data.canReopen', false);
        $this->postJson(self::ROOT.'evidence/'.$replacement['id'].'/reopen', ['observations' => 'Liberación vigente', 'expectedReviewNumber' => 1], $this->headers())->assertConflict();
    }

    public function test_reports_require_approved_project_order_grades_and_independent_reviewer(): void
    {
        $this->fixture->initializeAndApprove();
        $first = $this->fixture->submitReport();
        $this->login($this->fixture->reviewer);
        $body = ['evidenceId' => $first['evidenceId'], 'decision' => 'APROBADO', 'expectedReviewNumber' => 1];
        $this->postJson($this->path('reports/reviews'), $body, $this->headers())->assertUnprocessable();
        $this->postJson($this->path('reports/reviews'), $body + ['grade' => 96.5], $this->headers())->assertCreated()->assertJsonPath('data.status', 'APROBADO');
        $this->postJson($this->path('reports/reviews'), $body + ['grade' => 96.5], $this->headers())->assertConflict();
        $second = $this->fixture->submitReport('PARCIAL', 2);
        $this->fixture->grant($this->fixture->residentUser, 'DRPSS_REVISOR');
        $this->login($this->fixture->residentUser);
        $this->postJson($this->path('reports/reviews'), array_replace($body, ['evidenceId' => $second['evidenceId'], 'grade' => 90]), $this->headers())->assertForbidden();
        $this->getJson(self::ROOT.'reports/review-queue')->assertOk()->assertJsonCount(0, 'data.items');
        $this->login($this->fixture->reviewer);
        $this->postJson(self::ROOT.'evidence/'.$second['evidenceId'].'/reviews', ['decision' => 'APROBADO', 'expectedReviewNumber' => 1], $this->headers())->assertUnprocessable();
        $this->fixture->approveReport($second);
        $this->fixture->approveReport($this->fixture->submitReport('FINAL'));
        $this->getJson($this->path('release'))->assertOk()->assertJsonPath('data.eligibility.eligible', true);
    }

    public function test_release_cannot_precede_reports_and_resolution_preserves_history_and_project_state(): void
    {
        $this->fixture->initializeAndApprove();
        $body = ['decision' => 'APROBADO', 'observations' => 'Validación piloto', 'expectedVersion' => 0];
        $this->postJson($this->path('release/resolve'), $body, $this->headers())->assertConflict();
        $this->fixture->completeReports();
        $key = (string) Str::uuid();
        $this->postJson($this->path('release/resolve'), $body, $this->headers($key))->assertCreated()->assertJsonPath('data.projectStatus', 'COMPLETADO');
        $this->postJson($this->path('release/resolve'), $body, $this->headers($key))->assertCreated()->assertJsonPath('data.version', 1);
        $this->postJson($this->path('release/resolve'), $body, $this->headers())->assertConflict();
        $this->assertDatabaseCount('residencias_historial_liberaciones', 1);
        $this->postJson($this->path('release/reopen'), ['observations' => 'Corrección administrativa', 'expectedVersion' => 1], $this->headers())->assertCreated()->assertJsonPath('data.status', 'PENDIENTE')->assertJsonPath('data.projectStatus', 'APROBADO');
        $this->getJson($this->path('release/history'))->assertOk()->assertJsonCount(2, 'data.items');
    }

    public function test_reopening_an_approved_partial_invalidates_downstream_dependencies_without_rewriting_history(): void
    {
        $this->fixture->initializeAndApprove();
        $this->fixture->completeReports();
        $first = collect($this->fixture->plan()['items'])->firstWhere('type', 'PARCIAL');
        $this->postJson(self::ROOT.'evidence/'.$first['evidenceId'].'/reopen', ['observations' => 'Revisión adicional', 'expectedReviewNumber' => 1], $this->headers())->assertCreated();
        $this->fixture->approveReport(array_replace($first, ['reviewNumber' => 1]));
        $plan = $this->fixture->plan();
        $this->assertFalse(collect($plan['items'])->firstWhere('type', 'FINAL')['dependenciesCurrent']);
        $this->getJson($this->path('release'))->assertOk()->assertJsonPath('data.eligibility.eligible', false);
        $this->assertDatabaseCount('residencias_revisiones_evidencia', 4);
        $this->getJson(self::ROOT.'evidence/'.$first['evidenceId'].'/reopen-history')->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_document_snapshot_and_html_survive_live_edits_and_download_checks_integrity(): void
    {
        $template = $this->fixture->template();
        $key = (string) Str::uuid();
        $document = $this->fixture->issue($template, ['recipientContactId' => $this->fixture->contact], $key);
        $this->assertSame($document['id'], $this->fixture->issue($template, ['recipientContactId' => $this->fixture->contact], $key)['id']);
        $before = DB::table('residencias_documentos_emitidos')->where('id', $document['id'])->first();
        DB::table('residencias_empresas')->where('id', $this->fixture->company)->update(['razon_social' => 'Nombre posterior']);
        DB::table('organizacion_empleados')->where('id', $this->fixture->employee)->update(['nombres' => 'Firma posterior']);
        app(DocumentWorkflow::class)->render($document['id']);
        $this->login($this->fixture->residentUser);
        $this->get(self::ROOT.'issued-documents/'.$document['id'].'/download')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $after = DB::table('residencias_documentos_emitidos')->where('id', $document['id'])->first();
        $this->assertSame($before->datos_emision, $after->datos_emision);
        $this->assertSame($before->html_emision, $after->html_emision);
        $this->assertStringNotContainsString('Nombre posterior', $after->datos_emision);
        Storage::disk('local')->put('clean/'.$after->id_archivo, 'tampered');
        $this->get(self::ROOT.'issued-documents/'.$document['id'].'/download')->assertStatus(503)->assertDontSee('tampered');
    }

    public function test_failed_renderer_retry_keeps_document_snapshot_and_private_details_hidden(): void
    {
        $document = $this->fixture->issue($this->fixture->template());
        $this->control->render = false;
        try {
            app(DocumentWorkflow::class)->render($document['id']);
            $this->fail('Expected renderer failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('DOCUMENT_RENDER_FAILED', $exception->getMessage());
        }
        $failed = app(DocumentWorkflow::class)->read('document', $document['id'], [], $this->fixture->administrator->id);
        $this->assertSame('FALLIDO', $failed['status']);
        $this->control->render = true;
        $this->postJson(self::ROOT.'issued-documents/'.$document['id'].'/retry', ['expectedVersion' => $failed['version']], $this->headers())->assertAccepted()->assertJsonPath('data.id', $document['id'])->assertJsonPath('data.snapshotHash', $document['snapshotHash']);
        app(DocumentWorkflow::class)->render($document['id']);
        $this->getJson(self::ROOT.'issued-documents/'.$document['id'])->assertOk()->assertJsonPath('data.status', 'LISTO')->assertDontSee('private renderer detail');
        app(DocumentWorkflow::class)->render($document['id']);
        $this->assertDatabaseCount('residencias_documentos_emitidos', 1);
    }

    public function test_grouped_certificate_is_global_only_and_release_reopening_annuls_it_without_reusing_folio(): void
    {
        $this->fixture->initializeAndApprove();
        $this->fixture->completeReports();
        $this->fixture->release();
        $template = $this->fixture->template('CONSTANCIA_ASESORIA');
        $extra = ['adviserAssignmentId' => $this->fixture->assignment, 'year' => 2026, 'semester' => 1, 'startsOn' => '2026-02-01', 'endsOn' => '2026-07-31'];
        $document = $this->fixture->issue($template, $extra);
        $this->assertSame('ITSH/RPSS/2026(1)/001', $document['folio']);
        app(DocumentWorkflow::class)->render($document['id']);
        $this->login($this->fixture->residentUser);
        $this->getJson(self::ROOT.'issued-documents/'.$document['id'])->assertForbidden();
        $this->get(self::ROOT.'issued-documents/'.$document['id'].'/download')->assertForbidden();
        $this->getJson($this->path('documents'))->assertOk()->assertJsonCount(0, 'data.items');
        $this->login($this->fixture->administrator);
        $this->postJson($this->path('release/reopen'), ['observations' => 'Corrección del expediente', 'expectedVersion' => 1], $this->headers())->assertCreated();
        $this->getJson(self::ROOT.'issued-documents/'.$document['id'])->assertOk()->assertJsonPath('data.status', 'ANULADO');
        $this->get(self::ROOT.'issued-documents/'.$document['id'].'/download')->assertConflict();
        $this->fixture->progress('release-resolve', ['decision' => 'APROBADO', 'observations' => 'Expediente conciliado', 'expectedVersion' => 2]);
        $second = $this->fixture->issue($template, $extra);
        $this->assertSame('ITSH/RPSS/2026(1)/002', $second['folio']);
        $this->assertDatabaseCount('residencias_anulaciones_documento', 1);
    }

    public function test_certificate_academic_year_is_independent_of_issue_year_and_still_requires_a_matching_release(): void
    {
        $this->travelTo(Carbon::parse('2025-10-31 12:00:00', 'America/Mexico_City'));
        try {
            $this->login($this->fixture->administrator);
            $this->fixture->initializeAndApprove();
            $this->fixture->completeReports();
            $template = $this->fixture->template('CONSTANCIA_ASESORIA');
            $body = ['type' => $template['type'], 'templateId' => $template['id'], 'expectedTemplateVersion' => $template['version'],
                'adviserAssignmentId' => $this->fixture->assignment, 'year' => 2026, 'semester' => 1, 'startsOn' => '2026-02-01', 'endsOn' => '2026-07-31'];
            $this->postJson($this->path('documents'), $body, $this->headers())->assertConflict();
            $this->fixture->release();
            $document = $this->postJson($this->path('documents'), $body, $this->headers())->assertAccepted()->assertJsonPath('data.folio', 'ITSH/RPSS/2026(1)/001')->json('data');
            $this->assertSame('2025', substr(DB::table('residencias_documentos_emitidos')->where('id', $document['id'])->value('emitido_en'), 0, 4));
            $this->postJson($this->path('documents'), array_replace($body, ['semester' => 2, 'startsOn' => '2026-08-01', 'endsOn' => '2026-12-31']), $this->headers())->assertConflict();
            $this->assertDatabaseCount('residencias_constancias', 1);
            $this->assertDatabaseCount('residencias_secuencias_folio', 1);
            $this->assertSame(1, (int) DB::table('residencias_secuencias_folio')->value('ultimo_numero'));
            $this->login($this->fixture->residentUser);
            $this->getJson(self::ROOT.'issued-documents/'.$document['id'])->assertForbidden();
        } finally {
            $this->travelBack();
        }
    }

    public function test_preceding_approval_is_required_and_generic_evidence_cannot_bypass_report_linking(): void
    {
        $this->fixture->initializeAndApprove();
        $this->login($this->fixture->residentUser);
        $second = collect($this->fixture->plan()['items'])->first(fn (array $row): bool => $row['type'] === 'PARCIAL' && $row['sequence'] === 2);
        $file = UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\n% test\n%%EOF");
        $this->post(self::ROOT.'files/staged', ['purpose' => 'RESIDENCY_EVIDENCE', 'residentId' => $this->fixture->resident, 'projectId' => $this->fixture->project, 'requirementId' => $second['requirementId'], 'file' => $file], $this->headers() + ['Accept' => 'application/json'])->assertConflict();
        $stage = $this->fixture->staged();
        $first = $this->fixture->plan()['items'][0];
        $this->postJson(self::ROOT.'evidence', ['residentId' => $this->fixture->resident, 'projectId' => $this->fixture->project, 'requirementId' => $first['requirementId'], 'stagedFileId' => $stage['id'], 'expectedSubmissionVersion' => 0], $this->headers())->assertConflict();
        $this->assertDatabaseCount('residencias_entregas_evidencia', 0);
    }

    public function test_outbox_delivers_one_document_job_and_worker_publishes_one_private_pdf(): void
    {
        $document = $this->fixture->issue($this->fixture->template());
        $event = DB::table('compartido_mensajes_salida')->where('tipo_evento', 'residencies.document-requested.v1')->first();
        $processor = app(OutboxProcessor::class);
        for ($i = 0; $i < 3; $i++) {
            $processor->runOnce();
        }
        $this->assertDatabaseHas('compartido_mensajes_salida', ['id' => $event->id, 'estado' => 'PUBLISHED']);
        $this->assertDatabaseCount('jobs', 1);
        DB::table('compartido_mensajes_salida')->where('id', $event->id)->update(['estado' => 'PENDING']);
        $processor->runOnce();
        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['connection' => 'documents', '--queue' => 'documents', '--once' => true, '--tries' => 1, '--sleep' => 0])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseHas('residencias_documentos_emitidos', ['id' => $document['id'], 'estado' => 'LISTO']);
    }

    public function test_infected_generated_pdf_is_never_published_and_snapshot_input_is_server_owned(): void
    {
        $template = $this->fixture->template();
        $this->postJson($this->path('documents'), ['type' => $template['type'], 'templateId' => $template['id'], 'expectedTemplateVersion' => 2, 'folio' => 'injected', 'snapshot' => ['name' => 'injected'], 'html' => '<script>injected</script>'], $this->headers())->assertUnprocessable();
        $this->assertDatabaseCount('residencias_documentos_emitidos', 0);
        $document = $this->fixture->issue($template);
        $this->control->clean = false;
        try {
            app(DocumentWorkflow::class)->render($document['id']);
            $this->fail('Expected infected render failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('DOCUMENT_RENDER_FAILED', $exception->getMessage());
        }
        $this->getJson(self::ROOT.'issued-documents/'.$document['id'])->assertOk()->assertJsonPath('data.status', 'FALLIDO')->assertJsonPath('data.fileHash', null);
        $this->get(self::ROOT.'issued-documents/'.$document['id'].'/download')->assertConflict();
        $this->assertDatabaseHas('residencias_documentos_emitidos', ['id' => $document['id'], 'id_archivo' => null]);
    }

    public function test_approved_release_prevents_documentary_reopening_and_own_release_decisions_are_forbidden(): void
    {
        $this->fixture->initializeAndApprove();
        $this->fixture->completeReports();
        $this->fixture->release();
        $first = $this->fixture->plan()['items'][0];
        $this->postJson(self::ROOT.'evidence/'.$first['evidenceId'].'/reopen', ['observations' => 'Intento sin reabrir liberación', 'expectedReviewNumber' => 1], $this->headers())->assertConflict();
        $this->fixture->grant($this->fixture->residentUser, 'ADMIN_RESIDENCIAS');
        $this->login($this->fixture->residentUser);
        $this->postJson($this->path('release/reopen'), ['observations' => 'Decisión sobre expediente propio', 'expectedVersion' => 1], $this->headers())->assertForbidden();
        $this->assertDatabaseCount('residencias_historial_liberaciones', 1);
    }

    public function test_template_resources_are_actor_bound_idempotent_and_consumed_once(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 20, 20);
        $key = (string) Str::uuid();
        $service = app(DocumentWorkflow::class);
        $resource = $service->stageResource($file->getPathname(), $key, $this->fixture->administrator->id, (string) Str::uuid());
        $this->assertSame($resource['id'], $service->stageResource($file->getPathname(), $key, $this->fixture->administrator->id, (string) Str::uuid())['id']);
        $body = ['type' => 'SOLICITUD_RESIDENCIA', 'name' => 'Solicitud piloto', 'expectedPreviousVersion' => 1, 'active' => true, 'departmentSignerId' => $this->fixture->employee, 'resourceIds' => [$resource['id']]];
        $this->fixture->grant($this->fixture->reviewer, 'ADMIN_RESIDENCIAS');
        $this->login($this->fixture->reviewer);
        $this->postJson(self::ROOT.'document-templates', $body, $this->headers())->assertForbidden();
        $this->login($this->fixture->administrator);
        $this->postJson(self::ROOT.'document-templates', $body, $this->headers())->assertCreated()->assertJsonCount(1, 'data.resources');
        $this->postJson(self::ROOT.'document-templates', array_replace($body, ['expectedPreviousVersion' => 2]), $this->headers())->assertConflict();
        $this->assertDatabaseCount('residencias_recursos_preparados', 1);
        $this->assertSame(2, DB::table('residencias_plantillas_documento')->where('codigo', 'SOLICITUD_RESIDENCIA')->max('numero_version'));
    }

    public function test_revocation_during_pdf_scan_preserves_tracking_without_publishing_a_document(): void
    {
        $document = $this->fixture->issue($this->fixture->template());
        $this->control->scanCallback = function (): void {
            DB::table('iam_identidad_roles')->where('id_identidad', $this->fixture->administrator->id)->delete();
        };
        try {
            app(DocumentWorkflow::class)->render($document['id']);
            $this->fail('Expected reauthorization failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('DOCUMENT_RENDER_FAILED', $exception->getMessage());
        }
        $this->assertDatabaseHas('residencias_documentos_emitidos', ['id' => $document['id'], 'estado' => 'FALLIDO', 'id_archivo' => null]);
        $this->assertDatabaseCount('compartido_archivos_almacenados', 1);
        $this->login($this->fixture->residentUser);
        $this->get(self::ROOT.'issued-documents/'.$document['id'].'/download')->assertConflict();
    }

    public function test_revoked_emitter_before_job_claim_fails_visibly_instead_of_remaining_pending(): void
    {
        $document = $this->fixture->issue($this->fixture->template());
        DB::table('iam_identidad_roles')->where('id_identidad', $this->fixture->administrator->id)->delete();
        app(DocumentWorkflow::class)->render($document['id']);
        $this->assertDatabaseHas('residencias_documentos_emitidos', ['id' => $document['id'], 'estado' => 'FALLIDO', 'ultimo_error' => 'RENDER_PRECONDITION_FAILED', 'id_archivo' => null]);
        $this->assertDatabaseCount('compartido_archivos_almacenados', 0);
    }

    public function test_document_list_has_a_bounded_query_budget_and_omits_snapshots_and_html(): void
    {
        $template = $this->fixture->template();
        for ($i = 0; $i < 10; $i++) {
            $this->fixture->issue($template);
        }
        DB::enableQueryLog();
        try {
            $page = app(DocumentWorkflow::class)->read('project-documents', $this->fixture->project, [], $this->fixture->administrator->id);
            $queries = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertCount(10, $page['items']);
        $this->assertLessThanOrEqual(15, $queries);
        $json = json_encode($page, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('data:image', $json);
        $this->assertStringNotContainsString('html_emision', $json);
    }

    public function test_certificate_groups_only_released_projects_for_the_same_adviser_program_and_semester(): void
    {
        $this->fixture->initializeAndApprove();
        $this->fixture->completeReports();
        $this->fixture->release();
        $originalProject = $this->fixture->project;
        $originalResident = $this->fixture->resident;
        $originalUser = $this->fixture->residentUser;
        $otherUser = User::factory()->create();
        $this->fixture->grant($otherUser, 'RESIDENTE');
        $other = $this->fixture->create('residents', ['identityId' => $otherUser->id, 'controlNumber' => '99009902', 'programId' => $this->fixture->program, 'givenNames' => 'Otra persona', 'firstSurname' => 'Piloto']);
        $period = DB::table('residencias_proyectos')->where('id', $originalProject)->value('id_periodo');
        $project = $this->fixture->create('projects', ['residentId' => $other['id'], 'periodId' => $period, 'companyId' => $this->fixture->company, 'programId' => $this->fixture->program, 'name' => 'Segundo proyecto piloto', 'vacancies' => 1, 'startsOn' => '2026-02-01', 'endsOn' => '2026-05-31']);
        $this->fixture->create('internal-advisers', ['employeeId' => $this->fixture->employee, 'roleCode' => 'PRINCIPAL', 'startsOn' => '2026-02-01'], $project['id'], 1);
        $this->fixture->create('external-advisers', ['contactId' => $this->fixture->contact, 'roleCode' => 'PRINCIPAL', 'startsOn' => '2026-02-01'], $project['id'], 2);
        $template = $this->fixture->template('CONSTANCIA_ASESORIA');
        $extra = ['adviserAssignmentId' => $this->fixture->assignment, 'year' => 2026, 'semester' => 1, 'startsOn' => '2026-02-01', 'endsOn' => '2026-07-31'];
        $first = $this->fixture->issue($template, $extra);
        $snapshot = json_decode(DB::table('residencias_documentos_emitidos')->where('id', $first['id'])->value('datos_emision'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([$originalProject], $snapshot['contributingProjects']);
        $this->fixture->project = $project['id'];
        $this->fixture->resident = $other['id'];
        $this->fixture->residentUser = $otherUser;
        $this->fixture->initializeAndApprove();
        $this->fixture->completeReports();
        $this->fixture->release();
        $this->fixture->project = $originalProject;
        $this->fixture->resident = $originalResident;
        $this->fixture->residentUser = $originalUser;
        $second = $this->fixture->issue($template, $extra);
        $snapshot = json_decode(DB::table('residencias_documentos_emitidos')->where('id', $second['id'])->value('datos_emision'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertEqualsCanonicalizing([$originalProject, $project['id']], $snapshot['contributingProjects']);
        $this->assertCount(2, $snapshot['certificateFields']['filas']);
        $this->login($otherUser);
        $this->getJson(self::ROOT.'issued-documents/'.$second['id'])->assertForbidden();
        $this->fixture->grant($otherUser, 'ADMIN_RESIDENCIAS');
        $this->login($otherUser);
        $this->postJson($this->path('documents'), ['type' => $template['type'], 'templateId' => $template['id'], 'expectedTemplateVersion' => 2] + $extra, $this->headers())->assertForbidden();
        $this->postJson(self::ROOT.'issued-documents/'.$second['id'].'/annul', ['observations' => 'Decisión sobre una constancia que incluye expediente propio', 'expectedVersion' => 1], $this->headers())->assertForbidden();
        $this->assertDatabaseCount('residencias_documentos_emitidos', 2);
        $this->assertSame(2, DB::table('residencias_secuencias_folio')->value('ultimo_numero'));
    }

    public function test_document_worker_monitor_detects_stale_heartbeats_and_ignores_other_queues(): void
    {
        $this->artisan('residencies:documents-health')->assertFailed();
        event(new Looping('database', 'default'));
        $this->artisan('residencies:documents-health')->assertFailed();
        event(new Looping('documents', 'documents'));
        $this->artisan('residencies:documents-health')->assertSuccessful();
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'documents', 'queue' => 'documents', 'payload' => '{}', 'exception' => 'Synthetic historical failure', 'failed_at' => now()]);
        $this->artisan('residencies:documents-health')->expectsOutputToContain('"failedJobs":1')->assertSuccessful();
        $this->travel(181)->seconds();
        $this->artisan('residencies:documents-health')->assertFailed();
    }
}
