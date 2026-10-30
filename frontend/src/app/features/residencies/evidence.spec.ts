import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { EvidencePreparedFile, EvidenceRecord, EvidenceRequirement, EvidenceRequirementInput, ResidencyRecord } from '../../api/models';
import { AuthFacade } from '../../core/auth/auth.facade';
import { activeIdentity } from '../../core/auth/identity.fixture';
import { EvidenceWorkspace } from './evidence';

const identity = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const residentId = '01ARZ3NDEKTSV4RRFFQ69G5FAW';
const requirementId = '01ARZ3NDEKTSV4RRFFQ69G5FAX';
const evidenceId = '01ARZ3NDEKTSV4RRFFQ69G5FAY';
const stagedId = '01ARZ3NDEKTSV4RRFFQ69G5FAZ';
const core = { active: true, allowedMimes: ['text/plain' as const], code: 'PROOF', displayOrder: 0, maxBytes: 10485760, name: 'Constancia de ensayo', programId: null, required: true, scope: 'RESIDENTE' as const, stage: 'PRERREQUISITO' as const, validFrom: null, validUntil: null };
interface Harness {
  changeView(view: 'file' | 'definitions' | 'queue'): void;
  changeContext(): void;
  selectRequirement(row: EvidenceRequirement): void;
  chooseFile(event: Event): void;
  stage(): void;
  submit(): void;
  openReview(row: EvidenceRecord): void;
  review(): void;
  reopen(): void;
  reopeningReason: string;
  saveDefinition(): void;
  definitionDraft: EvidenceRequirementInput;
  definition: Signal<EvidenceRequirement | null>;
  selected: Signal<EvidenceRequirement | null>;
  prepared: Signal<EvidencePreparedFile | null>;
  projectId: string;
  decision: 'APROBADO' | 'RECHAZADO' | 'CAMBIOS_SOLICITADOS';
  observations: string;
}
describe('Expediente documental', () => {
  let http: HttpTestingController; let own: ResidencyRecord | null; let requirement: EvidenceRequirement; let evidence: EvidenceRecord; let refreshQueries: string[];
  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [EvidenceWorkspace], providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()] });
    http = TestBed.inject(HttpTestingController); refreshQueries = [];
    own = { id: residentId, version: 1, kind: 'residents', name: 'Persona Piloto', controlNumber: '99009901', status: 'ACTIVO' };
    requirement = { id: requirementId, version: 1, ...core, canSubmit: true, latestEvidence: null };
    evidence = { id: evidenceId, version: 1, residentId, projectId: null, requirementId, submissionVersion: 1, status: 'ENVIADO', reviewNumber: 0, requirementSnapshot: { id: requirementId, version: 1, ...core }, originalName: '<img src=x onerror=alert(1)>.txt', mime: 'text/plain', size: 13, sha256: 'a'.repeat(64), submittedAt: '2026-10-24T12:00:00Z', replacedAt: null, canReview: true, reviews: [] };
    document.cookie = 'XSRF-TOKEN=evidence-unit; path=/';
  });
  afterEach(() => { document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/'; http.verify(); });
  const harness = (fixture: ComponentFixture<EvidenceWorkspace>) => fixture.componentInstance as unknown as Harness;
  function drain(fixture: ComponentFixture<EvidenceWorkspace>) {
    for (let i = 0; i < 20; i++) {
      const requests = http.match(request => request.method === 'GET' && request.url.startsWith('/api/v1/residencies/'));
      if (!requests.length) break;
      for (const request of requests) {
        const path = request.request.url.replace('/api/v1/residencies/', '');
        if (path === 'me') request.flush({ data: { resident: own, projects: { items: [], nextCursor: null } } });
        else if (path.startsWith('files/')) {
          if (request.request.params.has('requirementId')) refreshQueries.push(request.request.params.get('requirementId')!);
          request.flush({ data: { items: [requirement], nextCursor: null } });
        } else if (path === 'requirements') request.flush({ data: { items: [requirement], nextCursor: null } });
        else if (path === 'requirements/' + requirementId) request.flush({ data: requirement });
        else if (path === 'review-queue') request.flush({ data: { items: evidence.canReview ? [evidence] : [], nextCursor: null } });
        else if (path === 'evidence/' + evidenceId) request.flush({ data: evidence });
        else if (path.endsWith('/reopen-history')) request.flush({ data: { items: [], nextCursor: null, canReopen: evidence.status === 'APROBADO' } });
        else if (path.endsWith('/history')) request.flush({ data: { items: [evidence], nextCursor: null } });
        else if (path === 'residents') request.flush({ data: { items: own ? [own] : [], nextCursor: null } });
        else request.flush({ data: { items: [], nextCursor: null } });
      }
    }
    fixture.detectChanges();
  }
  function setup(permissions: string[], global = false, enrolled = true) {
    if (!enrolled) own = null;
    TestBed.inject(AuthFacade).refresh().subscribe();
    http.expectOne('/api/v1/me').flush({ data: { ...activeIdentity(), id: identity, permissions, scopes: permissions.map(permission => permission + (global ? ':global' : ':self')) } });
    const fixture = TestBed.createComponent(EvidenceWorkspace); fixture.detectChanges(); drain(fixture); return fixture;
  }
  function choose(component: Harness) { component.chooseFile({ target: { files: [new File(['test document'], 'proof.txt', { type: 'text/plain' })] } } as unknown as Event); }
  it('reabre una evidencia aprobada con motivo, versión y clave estable y actualiza el expediente', () => {
    evidence = { ...evidence, status: 'APROBADO', reviewNumber: 1, canReview: false };
    requirement = { ...requirement, latestEvidence: evidence, canSubmit: false };
    const fixture = setup(['residencies.files.read', 'residencies.evidence.reopen']); const component = harness(fixture);
    component.selectRequirement(requirement); drain(fixture);
    expect(fixture.nativeElement.textContent).toContain('Reabrir revisión documental');
    component.reopeningReason = 'Corrección de ensayo'; component.reopen(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const first = http.expectOne('/api/v1/residencies/evidence/' + evidenceId + '/reopen');
    expect(first.request.body).toEqual({ observations: 'Corrección de ensayo', expectedReviewNumber: 1 });
    const key = first.request.headers.get('Idempotency-Key'); first.error(new ProgressEvent('network')); drain(fixture);
    component.reopen(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const retry = http.expectOne('/api/v1/residencies/evidence/' + evidenceId + '/reopen'); expect(retry.request.headers.get('Idempotency-Key')).toBe(key);
    evidence = { ...evidence, status: 'ENVIADO', canReview: true }; requirement = { ...requirement, latestEvidence: evidence, canSubmit: true };
    retry.flush({ data: { evidenceId, status: 'ENVIADO', reviewNumber: 1, reopeningNumber: 1 } }); drain(fixture);
    expect(component.selected()?.latestEvidence?.status).toBe('ENVIADO');
    expect(fixture.nativeElement.textContent).not.toContain('Reabrir revisión documental'); fixture.destroy();
  });
  it('explica la inscripción pendiente sin ofrecer acciones de administración o revisión', () => {
    const fixture = setup([], false, false);
    expect(fixture.nativeElement.textContent).toContain('Solicita la inscripción a administración');
    expect(fixture.nativeElement.textContent).not.toContain('Administración de requisitos');
    expect(fixture.nativeElement.querySelector('input[type=file]')).toBeNull(); fixture.destroy();
  });
  it('conserva claves al reintentar carga y envío, enlaza el objetivo y refresca la selección de cualquier página', () => {
    const fixture = setup(['residencies.files.read', 'residencies.evidence.submit']); const component = harness(fixture);
    component.selectRequirement(requirement); choose(component); component.stage(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const first = http.expectOne('/api/v1/residencies/files/staged'); const uploadKey = first.request.headers.get('Idempotency-Key');
    expect(first.request.headers.get('X-XSRF-TOKEN')).toBe('evidence-unit');
    const form = first.request.body as FormData;
    expect(form.get('purpose')).toBe('RESIDENCY_EVIDENCE'); expect(form.get('residentId')).toBe(residentId); expect(form.get('requirementId')).toBe(requirementId); expect(form.has('projectId')).toBe(false);
    first.error(new ProgressEvent('network')); component.stage(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const retry = http.expectOne('/api/v1/residencies/files/staged'); expect(retry.request.headers.get('Idempotency-Key')).toBe(uploadKey);
    const prepared: EvidencePreparedFile = { id: stagedId, residentId, projectId: null, requirementId, requirementVersion: 1, status: 'READY', originalName: 'proof.txt', mime: 'text/plain', size: 13, sha256: 'a'.repeat(64), expiresAt: '2026-10-25T12:00:00Z' };
    retry.flush({ data: prepared }); component.submit(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const submit = http.expectOne(request => request.method === 'POST'); const key = submit.request.headers.get('Idempotency-Key');
    expect(submit.request.body).toEqual({ residentId, projectId: null, requirementId, stagedFileId: stagedId, expectedSubmissionVersion: 0 });
    submit.error(new ProgressEvent('network')); component.submit(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const submitRetry = http.expectOne(request => request.method === 'POST'); expect(submitRetry.request.headers.get('Idempotency-Key')).toBe(key);
    requirement = { ...requirement, latestEvidence: evidence }; submitRetry.flush({ data: evidence }); drain(fixture);
    expect(refreshQueries).toEqual([requirementId]); expect(component.selected()?.latestEvidence?.id).toBe(evidenceId);
    expect(fixture.nativeElement.querySelector('img')).toBeNull(); expect(fixture.nativeElement.textContent).toContain('<img src=x onerror=alert(1)>.txt'); fixture.destroy();
  });
  it('descarta la respuesta de una carga al cambiar el ámbito sin cambiar el destino capturado', () => {
    const fixture = setup(['residencies.files.read', 'residencies.evidence.submit']); const component = harness(fixture);
    component.selectRequirement(requirement); choose(component); component.stage();
    component.projectId = evidenceId; component.changeContext(); drain(fixture);
    http.expectOne('/sanctum/csrf-cookie').flush(null);
    const upload = http.expectOne('/api/v1/residencies/files/staged'); expect((upload.request.body as FormData).has('projectId')).toBe(false);
    upload.flush({ data: { id: stagedId, status: 'READY' } }); fixture.detectChanges();
    expect(component.prepared()).toBeNull(); fixture.destroy();
  });
  it('exige motivo para rechazar y retira la revisión al recargar un conflicto', () => {
    const fixture = setup(['residencies.files.read', 'residencies.evidence.review'], true, false); const component = harness(fixture);
    component.openReview(evidence); drain(fixture); component.decision = 'RECHAZADO'; component.review();
    http.expectNone('/sanctum/csrf-cookie'); fixture.detectChanges(); expect(fixture.nativeElement.textContent).toContain('Indica observaciones');
    component.observations = 'Motivo de ensayo'; component.review(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const request = http.expectOne(request => request.method === 'POST'); expect(request.request.body.expectedReviewNumber).toBe(1);
    expect(request.request.body.observations).toBe('Motivo de ensayo'); expect(request.request.headers.get('Idempotency-Key')).toBeTruthy();
    evidence = { ...evidence, version: 2, reviewNumber: 1, status: 'APROBADO', canReview: false };
    request.flush({}, { status: 409, statusText: 'Conflict' }); drain(fixture);
    expect(fixture.nativeElement.textContent).toContain('La entrega fue revisada o reemplazada');
    expect(fixture.nativeElement.textContent).not.toContain('Registrar revisión'); fixture.destroy();
  });
  it('administra requisitos con CSRF y toma la versión canónica antes de editar', () => {
    const fixture = setup(['residencies.files.read', 'residencies.requirements.manage'], true, false); const component = harness(fixture);
    component.changeView('definitions'); drain(fixture); component.definitionDraft = { ...core };
    component.saveDefinition(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const create = http.expectOne(request => request.method === 'POST'); expect(create.request.url).toBe('/api/v1/residencies/requirements'); expect(create.request.headers.get('Idempotency-Key')).toBeTruthy();
    requirement = { ...requirement, version: 2, name: 'Nombre canónico' }; create.flush({ data: { ...requirement, version: 1 } }); drain(fixture);
    expect(component.definition()?.version).toBe(2); component.saveDefinition(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const update = http.expectOne(request => request.method === 'PATCH'); expect(update.request.headers.get('If-Match')).toBe('"2"');
    expect(update.request.headers.get('X-XSRF-TOKEN')).toBe('evidence-unit'); expect(update.request.body.latestEvidence).toBeUndefined(); expect(update.request.body.version).toBeUndefined();
    update.flush({ data: { ...requirement, version: 3 } }); requirement = { ...requirement, version: 3 }; drain(fixture); fixture.destroy();
  });
});
