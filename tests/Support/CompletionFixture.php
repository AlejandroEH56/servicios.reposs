<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Residencies\Application\DocumentWorkflow;
use App\Modules\Residencies\Application\EvidenceWorkflow;
use App\Modules\Residencies\Application\ProgressWorkflow;
use App\Modules\Residencies\Application\ResidencyDirectory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CompletionFixture
{
    public User $administrator;

    public User $residentUser;

    public User $reviewer;

    public string $resident;

    public string $project;

    public string $program;

    public string $company;

    public string $contact;

    public string $employee;

    public string $assignment;

    public function __construct()
    {
        $this->administrator = User::factory()->create(['nombre_mostrado' => 'Administración sintética', 'correo_normalizado' => 'admin-s8@example.test']);
        $this->grant($this->administrator, 'ADMIN_RESIDENCIAS');
        $this->grant($this->administrator, 'ADMIN_ORGANIZACION');
        $this->residentUser = User::factory()->create(['nombre_mostrado' => 'Residente sintético', 'correo_normalizado' => 'resident-s8@example.test']);
        $this->grant($this->residentUser, 'RESIDENTE');
        $this->reviewer = User::factory()->create(['nombre_mostrado' => 'Revisor sintético', 'correo_normalizado' => 'reviewer-s8@example.test']);
        $this->grant($this->reviewer, 'DRPSS_REVISOR');
        $mode = $this->create('modalities', ['code' => 'S8', 'name' => 'Modalidad piloto', 'active' => true]);
        $this->program = $this->create('programs', ['code' => 'S8', 'name' => 'Ingeniería de ensayo', 'active' => true, 'modalityId' => $mode['id']])['id'];
        $period = $this->create('periods', ['code' => 'S8', 'name' => 'Periodo piloto', 'startsOn' => '2026-02-01', 'endsOn' => '2026-05-31', 'durationMonths' => 4, 'status' => 'ACTIVO']);
        $this->company = $this->create('companies', ['name' => 'Empresa de ensayo', 'rfc' => 'XAXX010101000', 'status' => 'ACTIVO', 'mobilePhone' => '0000000000'])['id'];
        $this->contact = $this->create('contacts', ['givenNames' => 'Contacto', 'firstSurname' => 'Piloto', 'active' => true, 'position' => 'Asesor externo'], $this->company)['id'];
        $this->resident = $this->create('residents', ['identityId' => $this->residentUser->id, 'controlNumber' => '99009901', 'programId' => $this->program, 'givenNames' => 'Persona', 'firstSurname' => 'Piloto', 'insuranceType' => 'IMSS'])['id'];
        $this->project = $this->create('projects', ['residentId' => $this->resident, 'periodId' => $period['id'], 'companyId' => $this->company, 'programId' => $this->program, 'name' => 'Proyecto piloto', 'vacancies' => 1, 'startsOn' => '2026-02-01', 'endsOn' => '2026-05-31', 'source' => 'PROPUESTA_PROPIA'])['id'];
        $this->employee = app(OrganizationDirectory::class)->execute('createEmployee', null, ['identityId' => $this->administrator->id, 'givenNames' => 'Asesor', 'firstSurname' => 'Piloto', 'isAdviser' => true], $this->administrator->id, (string) Str::uuid())['id'];
        $this->assignment = $this->create('internal-advisers', ['employeeId' => $this->employee, 'roleCode' => 'PRINCIPAL', 'startsOn' => '2026-02-01'], $this->project, 1)['id'];
        $this->create('external-advisers', ['contactId' => $this->contact, 'roleCode' => 'PRINCIPAL', 'startsOn' => '2026-02-01'], $this->project, 2);
    }

    public function grant(User $user, string $role): void
    {
        DB::table('iam_identidad_roles')->insert(['id_identidad' => $user->id, 'id_rol' => DB::table('iam_roles')->where('codigo', $role)->value('id'), 'id_identidad_otorgante' => $user->id, 'otorgado_en' => now()]);
    }

    /** @param array<string,mixed> $body
     * @return array<string,mixed> */
    public function create(string $kind, array $body, ?string $parent = null, ?int $version = null): array
    {
        return app(ResidencyDirectory::class)->execute($kind, null, $parent, $body, $version, (string) Str::uuid(), $this->administrator->id, (string) Str::uuid());
    }

    /** @param array<string,mixed> $body
     * @return array<string,mixed> */
    public function progress(string $operation, array $body = [], ?User $actor = null, ?string $resource = null, ?string $key = null): array
    {
        return app(ProgressWorkflow::class)->execute($operation, $resource ?? $this->project, $body, $key ?? (string) Str::uuid(), ($actor ?? $this->administrator)->id, (string) Str::uuid());
    }

    public function initializeAndApprove(): void
    {
        $this->progress('plan-initialize');
        $this->progress('project-approve', ['expectedVersion' => 3]);
    }

    /** @return array<string,mixed> */
    public function plan(): array
    {
        return app(ProgressWorkflow::class)->read('plan', $this->project, [], $this->administrator->id);
    }

    /** @return array<string,mixed> */
    public function staged(string $type = 'PARCIAL', int $sequence = 1, ?User $actor = null): array
    {
        $slot = collect($this->plan()['items'])->first(fn (array $r): bool => $r['type'] === $type && $r['sequence'] === $sequence);
        $file = UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n% Synthetic report ".Str::uuid()."\n%%EOF");

        return app(EvidenceWorkflow::class)->stage($file->getPathname(), 'report.pdf', ['residentId' => $this->resident, 'projectId' => $this->project, 'requirementId' => $slot['requirementId']], (string) Str::uuid(), ($actor ?? $this->residentUser)->id, (string) Str::uuid());
    }

    /** @return array<string,mixed> */
    public function submitReport(string $type = 'PARCIAL', int $sequence = 1, ?User $actor = null): array
    {
        $stage = $this->staged($type, $sequence, $actor);
        $slot = collect($this->plan()['items'])->first(fn (array $r): bool => $r['type'] === $type && $r['sequence'] === $sequence);

        return $this->progress('report-submit', ['type' => $type, 'sequence' => $sequence, 'stagedFileId' => $stage['id'], 'expectedSubmissionVersion' => $slot['submissionVersion']], $actor ?? $this->residentUser);
    }

    /** @param array<string,mixed> $report
     * @return array<string,mixed> */
    public function approveReport(array $report): array
    {
        return $this->progress('report-review', ['evidenceId' => $report['evidenceId'], 'decision' => 'APROBADO', 'grade' => 95.5, 'expectedReviewNumber' => $report['reviewNumber'] + 1], $this->reviewer);
    }

    public function completeReports(): void
    {
        foreach ([['PARCIAL', 1], ['PARCIAL', 2], ['FINAL', 1]] as [$type,$sequence]) {
            $this->approveReport($this->submitReport($type, $sequence));
        }
    }

    /** @return array<string,mixed> */
    public function release(): array
    {
        return $this->progress('release-resolve', ['decision' => 'APROBADO', 'observations' => 'Expediente conciliado de ensayo', 'expectedVersion' => 0]);
    }

    /** @return array<string,mixed> */
    public function template(string $type = 'SOLICITUD_RESIDENCIA'): array
    {
        return app(DocumentWorkflow::class)->execute('template-version', null, ['type' => $type, 'name' => $type, 'expectedPreviousVersion' => 1, 'active' => true, 'departmentSignerId' => $this->employee, 'divisionSignerId' => $this->employee], (string) Str::uuid(), $this->administrator->id, (string) Str::uuid());
    }

    /** @param array<string,mixed> $template
     * @param array<string,mixed> $extra
     * @return array<string,mixed> */
    public function issue(array $template, array $extra = [], ?string $key = null): array
    {
        return app(DocumentWorkflow::class)->execute('issue', $this->project, ['type' => $template['type'], 'templateId' => $template['id'], 'expectedTemplateVersion' => $template['version']] + $extra, $key ?? (string) Str::uuid(), $this->administrator->id, (string) Str::uuid());
    }
}
