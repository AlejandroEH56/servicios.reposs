import { Component, DOCUMENT, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule, NgForm } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { EvidencePreparedFile, EvidenceRecord, EvidenceRequirement, EvidenceRequirementInput, EvidenceReviewInput, ResidencyRecord } from '../../api/models';
import { createEvidenceRequirement, downloadResidencyEvidence$Jpeg, downloadResidencyEvidence$Pdf, downloadResidencyEvidence$Plain, downloadResidencyEvidence$Png, getEvidenceHistory, getEvidenceRequirement, getResidencyEvidence, listEvidenceRequirements, listEvidenceReviewQueue, listResidentRequirements, reviewResidencyEvidence, stageResidencyEvidence, submitResidencyEvidence, updateEvidenceRequirement } from '../../api/functions';
import { WorkspaceService } from '../../core/auth/workspace.service';
import { ResidencyClient } from './residency-client';
import { listResidencyEvidenceReopenings, reopenResidencyEvidence } from '../../api/functions';
import { ResidencyReopeningHistoryResponse } from '../../api/models';

type Mime = EvidenceRequirementInput['allowedMimes'][number];
type View = 'file' | 'definitions' | 'queue';
@Component({ selector: 'app-residency-evidence', imports: [FormsModule, RouterLink], providers: [WorkspaceService, ResidencyClient], templateUrl: './evidence.html' })
export class EvidenceWorkspace implements OnInit {
  protected readonly work = inject(WorkspaceService);
  private readonly directory = inject(ResidencyClient);
  private readonly document = inject(DOCUMENT);
  protected readonly view = signal<View>('file');
  protected readonly notice = signal('');
  protected readonly own = signal<ResidencyRecord | null>(null);
  protected readonly residents = signal<ResidencyRecord[]>([]);
  protected readonly projects = signal<ResidencyRecord[]>([]);
  protected readonly programs = signal<ResidencyRecord[]>([]);
  protected readonly requirements = signal<EvidenceRequirement[]>([]);
  protected readonly selected = signal<EvidenceRequirement | null>(null);
  protected readonly prepared = signal<EvidencePreparedFile | null>(null);
  protected readonly history = signal<EvidenceRecord[]>([]);
  protected readonly queue = signal<EvidenceRecord[]>([]);
  protected readonly reviewTarget = signal<EvidenceRecord | null>(null);
  protected readonly definition = signal<EvidenceRequirement | null>(null);
  protected readonly residentNext = signal<string | null>(null);
  protected readonly projectNext = signal<string | null>(null);
  protected readonly programNext = signal<string | null>(null);
  protected readonly next = signal<string | null>(null);
  protected readonly historyNext = signal<string | null>(null);
  protected readonly reopenings = signal<ResidencyReopeningHistoryResponse['data']['items']>([]);
  protected readonly reopeningNext = signal<string | null>(null);
  protected readonly canReopen = signal(false);
  protected reopeningReason = '';
  protected residentId = ''; protected projectId = ''; protected residentSearch = ''; protected definitionSearch = '';
  protected file: File | null = null;
  protected definitionDraft = this.blank(); protected maximumMb = 10;
  protected decision: EvidenceReviewInput['decision'] = 'APROBADO'; protected observations = ''; protected grade: number | null = null;
  protected readonly mimes: Mime[] = ['application/pdf', 'image/jpeg', 'image/png', 'text/plain'];
  private uploadKey = ''; private definitionSerial = 0;
  private readonly keys = new Map<string, { signature: string; key: string }>();
  protected readonly corrections = computed(() => {
    const labels: Record<string, string> = { file: 'Archivo', name: 'Nombre', code: 'Código', programId: 'Programa', stage: 'Etapa', scope: 'Alcance', allowedMimes: 'Tipos permitidos', maxBytes: 'Tamaño máximo', validFrom: 'Vigencia inicial', validUntil: 'Vigencia final', observations: 'Observaciones', grade: 'Calificación', displayOrder: 'Orden', residentId: 'Residente', requirementId: 'Requisito' };
    return [...new Set(this.work.invalidFields().map(field => labels[field.split('.')[0]]).filter(Boolean))];
  });
  ngOnInit(): void {
    this.work.run(this.directory.dashboard(), response => {
      this.own.set(response.data.resident);
      if (this.global()) this.loadResidents();
      else if (response.data.resident) { this.residentId = response.data.resident.id; this.changeContext(); this.loadProjects(); }
      if (this.work.can('residencies.requirements.manage')) this.loadPrograms();
      if (this.work.can('residencies.evidence.review') && !this.work.can('residencies.requirements.manage')) this.changeView('queue');
    });
  }
  protected global(): boolean { return this.work.auth.identity()?.scopes.includes('residencies.files.read:global') ?? false; }
  private context(): string { return this.residentId + '|' + this.projectId; }
  private blank(): EvidenceRequirementInput { return { code: '', name: '', programId: null, stage: 'PRERREQUISITO', scope: 'RESIDENTE', required: true, displayOrder: 0, validFrom: null, validUntil: null, active: true, allowedMimes: ['application/pdf'], maxBytes: 10485760 }; }
  protected changeView(view: View): void {
    this.clearReopenings();
    this.view.set(view); this.notice.set(''); this.next.set(null); this.requirements.set([]); this.reviewTarget.set(null); this.clearUpload(); this.history.set([]); this.selected.set(null);
    if (view === 'definitions') this.loadDefinitions(); else if (view === 'queue') this.loadQueue(); else this.loadRequirements();
  }
  protected loadResidents(more = false): void {
    this.work.run(this.directory.list('residents', { q: this.residentSearch || undefined, pageSize: 100, cursor: more ? this.residentNext() ?? undefined : undefined }), response => { this.residents.set(more ? [...this.residents(), ...response.data.items] : response.data.items); this.residentNext.set(response.data.nextCursor); });
  }
  protected loadPrograms(more = false): void {
    this.work.run(this.directory.list('programs', { pageSize: 100, cursor: more ? this.programNext() ?? undefined : undefined }), response => { this.programs.set(more ? [...this.programs(), ...response.data.items] : response.data.items); this.programNext.set(response.data.nextCursor); });
  }
  protected changeResident(): void { this.projectId = ''; this.projects.set([]); this.changeContext(); this.loadProjects(); }
  protected loadProjects(more = false): void {
    if (!this.residentId) return;
    const resident = this.residentId;
    this.work.run(this.directory.list('projects', { residentId: resident, pageSize: 100, cursor: more ? this.projectNext() ?? undefined : undefined }), response => {
      if (this.residentId !== resident) return;
      this.projects.set(more ? [...this.projects(), ...response.data.items] : response.data.items); this.projectNext.set(response.data.nextCursor);
    });
  }
  protected changeContext(): void {
    this.clearReopenings();
    this.requirements.set([]); this.selected.set(null); this.next.set(null); this.clearUpload(); this.history.set([]); this.historyNext.set(null); this.notice.set('');
    this.loadRequirements();
  }
  protected loadRequirements(more = false): void {
    if (!this.residentId || this.view() !== 'file') return;
    const context = this.context();
    this.work.run(this.work.api.invoke(listResidentRequirements, { record: this.residentId, projectId: this.projectId || undefined, pageSize: 25, cursor: more ? this.next() ?? undefined : undefined }), response => {
      if (context !== this.context() || this.view() !== 'file') return;
      this.requirements.set(more ? [...this.requirements(), ...response.data.items] : response.data.items); this.next.set(response.data.nextCursor);
      const selected = this.selected();
      if (selected) { const current = response.data.items.find(row => row.id === selected.id); if (current) this.selected.set(current); }
    });
  }
  protected selectRequirement(requirement: EvidenceRequirement): void {
    this.clearReopenings();
    this.selected.set(requirement); this.clearUpload(); this.history.set([]); this.historyNext.set(null); this.reviewTarget.set(null);
    if (requirement.latestEvidence) { this.loadHistory(requirement.latestEvidence); this.loadReopenings(); }
  }
  private clearReopenings(): void { this.reopenings.set([]); this.reopeningNext.set(null); this.canReopen.set(false); this.reopeningReason = ''; }
  protected loadReopenings(more = false): void {
    const evidence = this.selected()?.latestEvidence; if (!evidence || this.view() !== 'file') return;
    const context = this.context(); const requirement = this.selected()?.id;
    this.work.run(this.work.api.invoke(listResidencyEvidenceReopenings, { record: evidence.id, pageSize: 25, cursor: more ? this.reopeningNext() ?? undefined : undefined }), response => {
      if (context !== this.context() || this.view() !== 'file' || this.selected()?.id !== requirement || this.selected()?.latestEvidence?.id !== evidence.id) return;
      this.reopenings.set(more ? [...this.reopenings(), ...response.data.items] : response.data.items); this.reopeningNext.set(response.data.nextCursor); this.canReopen.set(response.data.canReopen ?? false);
    });
  }
  protected reopen(): void {
    const evidence = this.selected()?.latestEvidence; if (!evidence || !this.canReopen()) return;
    if (!this.reopeningReason.trim()) { this.work.message.set('Indica el motivo de reapertura.'); return; }
    const context = this.context(); const body = { observations: this.reopeningReason.trim(), expectedReviewNumber: evidence.reviewNumber }; const key = this.key('reopen', evidence.id, body);
    this.work.run(this.work.csrf(token => this.work.api.invoke(reopenResidencyEvidence, { record: evidence.id, 'X-XSRF-TOKEN': token, 'Idempotency-Key': key, body })), () => {
      if (context !== this.context() || this.view() !== 'file' || this.selected()?.latestEvidence?.id !== evidence.id) return;
      this.keys.delete('reopen'); this.canReopen.set(false); this.reopeningReason = ''; this.notice.set('Revisión documental reabierta. Se conserva la decisión anterior en el historial.'); this.refreshSelected();
    }, () => this.refreshSelected());
  }
  private clearUpload(): void { this.file = null; this.prepared.set(null); this.uploadKey = ''; this.keys.delete('submit'); }
  protected chooseFile(event: Event): void { const input = event.target as HTMLInputElement; this.file = input.files?.[0] ?? null; input.value = ''; this.prepared.set(null); this.uploadKey = crypto.randomUUID(); this.keys.delete('submit'); }
  protected mimeName(mime: Mime): string { return ({ 'application/pdf': 'PDF', 'image/jpeg': 'Imagen JPEG', 'image/png': 'Imagen PNG', 'text/plain': 'Texto plano' })[mime]; }
  protected allowedTypes(requirement: EvidenceRequirement): string { return requirement.allowedMimes.map(mime => this.mimeName(mime)).join(', '); }
  protected stage(): void {
    const requirement = this.selected(); const file = this.file;
    if (!requirement?.canSubmit || !file || !this.residentId) return;
    if (!file.size || file.size > requirement.maxBytes) { this.work.message.set('El archivo está vacío o supera el tamaño permitido.'); return; }
    const context = this.context(); const requirementId = requirement.id; const residentId = this.residentId; const projectId = this.projectId || undefined; const key = this.uploadKey;
    this.work.run(this.work.csrf(token => this.work.api.invoke(stageResidencyEvidence, { 'X-XSRF-TOKEN': token, 'Idempotency-Key': key,
      body: { purpose: 'RESIDENCY_EVIDENCE', residentId, projectId, requirementId, file } })), response => {
      if (this.view() !== 'file' || context !== this.context() || this.selected()?.id !== requirementId || this.file !== file) return;
      this.prepared.set(response.data); this.notice.set('Archivo comprobado. Revisa el nombre y pulsa Enviar evidencia.');
    }, () => { this.notice.set('La preparación cambió o venció. Vuelve a seleccionar el archivo para iniciar otra carga.'); });
  }
  private key(slot: string, context: string, body: unknown): string {
    const signature = JSON.stringify([context, body]); const old = this.keys.get(slot);
    if (old?.signature === signature) return old.key;
    const key = crypto.randomUUID(); this.keys.set(slot, { signature, key }); return key;
  }
  protected submit(): void {
    const staged = this.prepared(); const requirement = this.selected(); if (!staged || !requirement?.canSubmit) return;
    const body = { residentId: this.residentId, projectId: this.projectId || null, requirementId: requirement.id, stagedFileId: staged.id, expectedSubmissionVersion: requirement.latestEvidence?.submissionVersion ?? 0 };
    const context = this.context(); const key = this.key('submit', context, body);
    this.work.run(this.work.csrf(token => this.work.api.invoke(submitResidencyEvidence, { 'X-XSRF-TOKEN': token, 'Idempotency-Key': key, body })), response => {
      if (this.view() !== 'file' || context !== this.context() || this.selected()?.id !== requirement.id) return;
      this.clearUpload(); this.selected.set({ ...requirement, latestEvidence: response.data }); this.notice.set('Evidencia enviada. Las versiones anteriores permanecen en el historial.'); this.refreshSelected(); this.loadHistory(response.data);
    }, () => { this.notice.set('El expediente cambió. Revisa la versión actual antes de intentar otro envío.'); this.refreshSelected(); });
  }
  private refreshSelected(): void {
    const selected = this.selected(); if (!selected) return;
    const context = this.context();
    this.work.run(this.work.api.invoke(listResidentRequirements, { record: this.residentId, projectId: this.projectId || undefined, requirementId: selected.id, pageSize: 1 }), response => {
      if (this.view() !== 'file' || context !== this.context() || this.selected()?.id !== selected.id) return;
      const current = response.data.items[0] ?? null; this.selected.set(current);
      this.canReopen.set(false);
      if (current) { this.requirements.update(rows => rows.map(row => row.id === current.id ? current : row)); if (current.latestEvidence) { this.loadHistory(current.latestEvidence); this.loadReopenings(); } }
      else this.requirements.update(rows => rows.filter(row => row.id !== selected.id));
      if (!current || this.prepared()?.requirementVersion !== current.version) this.clearUpload();
    });
  }
  protected loadHistory(evidence: EvidenceRecord, more = false): void {
    const context = this.context(); const selection = this.selected()?.id; const review = this.reviewTarget()?.id;
    this.work.run(this.work.api.invoke(getEvidenceHistory, { record: evidence.id, pageSize: 25, cursor: more ? this.historyNext() ?? undefined : undefined }), response => {
      if (context !== this.context() || selection !== this.selected()?.id || review !== this.reviewTarget()?.id) return;
      this.history.set(more ? [...this.history(), ...response.data.items] : response.data.items); this.historyNext.set(response.data.nextCursor);
    });
  }
  protected download(evidence: EvidenceRecord): void {
    const params = { record: evidence.id };
    const request = evidence.mime === 'application/pdf' ? this.work.api.invoke(downloadResidencyEvidence$Pdf, params) : evidence.mime === 'image/jpeg' ? this.work.api.invoke(downloadResidencyEvidence$Jpeg, params) : evidence.mime === 'image/png' ? this.work.api.invoke(downloadResidencyEvidence$Png, params) : this.work.api.invoke(downloadResidencyEvidence$Plain, params);
    this.work.run(request, blob => {
      const href = URL.createObjectURL(blob); const link = this.document.createElement('a'); link.href = href;
      link.download = 'evidence-' + evidence.id + '.' + ({ 'application/pdf': 'pdf', 'image/jpeg': 'jpg', 'image/png': 'png', 'text/plain': 'txt' }[evidence.mime]);
      link.click(); setTimeout(() => URL.revokeObjectURL(href), 1000);
    });
  }
  protected loadDefinitions(more = false): void {
    const serial = ++this.definitionSerial;
    this.work.run(this.work.api.invoke(listEvidenceRequirements, { q: this.definitionSearch || undefined, pageSize: 25, cursor: more ? this.next() ?? undefined : undefined }), response => {
      if (this.view() !== 'definitions' || serial !== this.definitionSerial) return;
      this.requirements.set(more ? [...this.requirements(), ...response.data.items] : response.data.items); this.next.set(response.data.nextCursor);
    });
  }
  protected newDefinition(): void { this.definition.set(null); this.definitionDraft = this.blank(); this.maximumMb = 10; this.keys.delete('definition'); }
  protected openDefinition(row: EvidenceRequirement): void {
    this.work.run(this.work.api.invoke(getEvidenceRequirement, { record: row.id }), response => {
      if (this.view() !== 'definitions') return;
      const { id: _id, version: _version, canSubmit: _canSubmit, latestEvidence: _latest, ...body } = response.data;
      this.definition.set(response.data); this.definitionDraft = { ...body, allowedMimes: [...body.allowedMimes] }; this.maximumMb = body.maxBytes / 1048576;
    });
  }
  protected toggleMime(mime: Mime, checked: boolean): void { this.definitionDraft.allowedMimes = checked ? [...new Set([...this.definitionDraft.allowedMimes, mime])] : this.definitionDraft.allowedMimes.filter(value => value !== mime); }
  protected saveDefinition(form?: NgForm): void {
    if (form?.invalid || !this.definitionDraft.allowedMimes.length) { this.work.message.set('Completa los campos del requisito y selecciona al menos un tipo permitido.'); return; }
    const current = this.definition(); const body = { ...this.definitionDraft, programId: this.definitionDraft.programId || null, validFrom: this.definitionDraft.validFrom || null, validUntil: this.definitionDraft.validUntil || null, maxBytes: Math.floor(this.maximumMb * 1048576) };
    const key = this.key('definition', current?.id ?? 'new', body);
    this.work.run(this.work.csrf(token => current ? this.work.api.invoke(updateEvidenceRequirement, { record: current.id, 'If-Match': '"' + current.version + '"', 'X-XSRF-TOKEN': token, body }) : this.work.api.invoke(createEvidenceRequirement, { 'Idempotency-Key': key, 'X-XSRF-TOKEN': token, body })), response => {
      this.keys.delete('definition'); this.notice.set('Requisito guardado y auditado.'); this.openDefinition(response.data); this.loadDefinitions();
    }, () => { this.notice.set('La definición cambió o ya tiene entregas. Conserva programa, código, alcance, etapa, vigencias y límites originales.'); if (current) this.openDefinition(current); this.loadDefinitions(); });
  }
  protected loadQueue(more = false): void {
    this.work.run(this.work.api.invoke(listEvidenceReviewQueue, { pageSize: 25, cursor: more ? this.next() ?? undefined : undefined }), response => {
      if (this.view() !== 'queue') return;
      this.queue.set(more ? [...this.queue(), ...response.data.items] : response.data.items); this.next.set(response.data.nextCursor);
    });
  }
  protected openReview(evidence: EvidenceRecord): void {
    this.work.run(this.work.api.invoke(getResidencyEvidence, { record: evidence.id }), response => {
      if (this.view() !== 'queue') return;
      this.reviewTarget.set(response.data); this.history.set([]); this.historyNext.set(null); this.observations = ''; this.grade = null; this.decision = 'APROBADO'; this.loadHistory(response.data);
    });
  }
  protected review(form?: NgForm): void {
    const current = this.reviewTarget(); if (!current?.canReview) return;
    if (form?.invalid || this.decision !== 'APROBADO' && !this.observations.trim()) { this.work.message.set('Indica observaciones para rechazar o solicitar cambios.'); return; }
    const body: EvidenceReviewInput = { decision: this.decision, observations: this.observations.trim() || null, grade: this.grade, expectedReviewNumber: current.reviewNumber + 1 };
    const key = this.key('review', current.id, body);
    this.work.run(this.work.csrf(token => this.work.api.invoke(reviewResidencyEvidence, { record: current.id, 'Idempotency-Key': key, 'X-XSRF-TOKEN': token, body })), response => {
      if (this.reviewTarget()?.id !== current.id || this.view() !== 'queue') return;
      this.keys.delete('review'); this.reviewTarget.set(response.data); this.notice.set('Revisión registrada en el historial.'); this.loadQueue(); this.loadHistory(response.data);
    }, () => { this.notice.set('La entrega fue revisada o reemplazada. Revisa su estado actualizado.'); this.openReview(current); this.loadQueue(); });
  }
}
