import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule, NgForm } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ResidencyCandidate, ResidencyRecord } from '../../api/models';
import { WorkspaceService } from '../../core/auth/workspace.service';
import { ResidencyBody, ResidencyClient, ResidencyKind } from './residency-client';

interface Field {
  key: string;
  label: string;
  type?: 'text' | 'email' | 'date' | 'number' | 'checkbox' | 'reference' | 'status' | 'role' | 'textarea' | 'choice';
  choices?: {value:string;label:string}[];
  reference?: ResidencyKind | 'identities';
  optional?: boolean;
  createOnly?: boolean;
  immutable?: boolean;
  max?: number;
}
interface Option { id: string; name: string; programId?: string; status?: string }
const name: Field = { key: 'name', label: 'Nombre', max: 160 };
const code: Field = { key: 'code', label: 'Código', max: 40 };
const active: Field = { key: 'active', label: 'Activo', type: 'checkbox' };
const program: Field = { key: 'programId', label: 'Programa educativo', type: 'reference', reference: 'programs' };
const person: Field[] = [{ key: 'givenNames', label: 'Nombres', max: 160 }, { key: 'firstSurname', label: 'Primer apellido', max: 120 }, { key: 'secondSurname', label: 'Segundo apellido', optional: true, max: 120 }];
const dates: Field[] = [{ key: 'startsOn', label: 'Fecha de inicio', type: 'date' }, { key: 'endsOn', label: 'Fecha de fin inclusiva', type: 'date' }];
const companyFields: Field[] = [{ ...name, label: 'Razón social', max: 255 }, { key: 'rfc', label: 'RFC (único e inmutable)', max: 13, immutable: true }, { key: 'sectorId', label: 'Sector', type: 'reference', reference: 'sectors', optional: true }, { key: 'businessLineId', label: 'Ramo', type: 'reference', reference: 'business-lines', optional: true }, { key: 'mission', label: 'Misión', type: 'textarea', optional: true, max: 2000 }, { key: 'address', label: 'Domicilio', optional: true, max: 500 }, { key: 'city', label: 'Ciudad', optional: true, max: 120 }, { key: 'postalCode', label: 'Código postal', optional: true, max: 12 }, { key: 'phone', label: 'Teléfono', optional: true, max: 30 }, { key: 'email', label: 'Correo de la empresa', type: 'email', optional: true, max: 254 }, { key: 'fax', label: 'Fax', optional: true, max: 30 }, { key: 'status', label: 'Estado', type: 'status' }];
export const residencyDefinitions: Record<ResidencyKind, { label: string; fields: Field[] }> = {
  residents: { label: 'Expedientes de residentes', fields: [{ key: 'identityId', label: 'Identidad institucional', type: 'reference', reference: 'identities', createOnly: true }, { key: 'controlNumber', label: 'Número de control (8 o 9 dígitos)', createOnly: true, max: 9 }, program, ...person, {key:'insuranceType',label:'Tipo de seguro',type:'choice',optional:true,choices:[{value:'IMSS',label:'IMSS'},{value:'ISSSTE',label:'ISSSTE'}]}, { key: 'address', label: 'Domicilio', optional: true, max: 500 }, { key: 'city', label: 'Ciudad', optional: true, max: 120 }, { key: 'socialSecurityNumber', label: 'Número de seguro social', optional: true, max: 30 }, { key: 'phone', label: 'Teléfono', optional: true, max: 30 }, { key: 'mobilePhone', label: 'Teléfono celular', optional: true, max: 30 }, { key: 'curp', label: 'CURP (opcional, 18 letras mayúsculas o dígitos)', optional: true, max: 18 }] },
  companies: { label: 'Empresas globales', fields: [...companyFields, {key:'mobilePhone',label:'Celular de la empresa',optional:true,max:30}] },
  projects: { label: 'Proyectos', fields: [{ key: 'residentId', label: 'Residente', type: 'reference', reference: 'residents', immutable: true }, { key: 'periodId', label: 'Periodo de residencia', type: 'reference', reference: 'periods', immutable: true }, { key: 'companyId', label: 'Empresa', type: 'reference', reference: 'companies' }, { ...program, immutable: true }, { ...name, label: 'Nombre del proyecto', max: 255 }, { key: 'vacancies', label: 'Número de residentes/vacantes', type: 'number', max: 65535 }, {key:'source',label:'Procedencia del proyecto',type:'choice',optional:true,choices:[{value:'BANCO_PROYECTOS',label:'Banco de proyectos'},{value:'PROPUESTA_PROPIA',label:'Propuesta propia'}]}, ...dates] },
  modalities: { label: 'Modalidades', fields: [code, name, active] },
  programs: { label: 'Programas educativos', fields: [code, name, { key: 'modalityId', label: 'Modalidad', type: 'reference', reference: 'modalities' }, { key: 'validFrom', label: 'Vigencia desde', type: 'date', optional: true }, { key: 'validUntil', label: 'Vigencia hasta', type: 'date', optional: true }, active] },
  periods: { label: 'Periodos de residencia', fields: [code, name, ...dates, { key: 'durationMonths', label: 'Duración en meses (4, 5 o 6)', type: 'number', max: 6 }, { key: 'status', label: 'Estado', type: 'status' }] },
  sectors: { label: 'Sectores', fields: [code, name, active] },
  'business-lines': { label: 'Ramos', fields: [code, name, active] },
};

@Component({
  selector: 'app-residencies',
  imports: [FormsModule, RouterLink],
  providers: [WorkspaceService, ResidencyClient],
  templateUrl: './residencies.html',
})
export class Residencies implements OnInit {
  protected readonly work = inject(WorkspaceService);
  private readonly client = inject(ResidencyClient);
  protected readonly definitions = residencyDefinitions;
  protected readonly kinds = Object.keys(residencyDefinitions) as ResidencyKind[];
  protected readonly kind = signal<ResidencyKind>('residents');
  protected readonly records = signal<ResidencyRecord[]>([]);
  protected readonly selected = signal<ResidencyRecord | null>(null);
  protected readonly ownResident = signal<ResidencyRecord | null>(null);
  protected readonly nextCursor = signal<string | null>(null);
  protected readonly notice = signal('');
  protected readonly fields = computed(() => this.definitions[this.kind()].fields.filter(field => !field.createOnly || !this.selected()));
  protected readonly invalidLabels = computed(() => this.fields().filter(field => this.work.invalidFields().includes(field.key)).map(field => field.label));
  protected draft: ResidencyBody = {};
  protected search = '';
  protected referenceData: Record<string, Option[]> = {};
  protected referenceSearch: Record<string, string> = {};
  protected referenceNext: Record<string, string | null> = {};
  protected readonly contacts = signal<ResidencyRecord[]>([]);
  protected readonly contactsNext = signal<string | null>(null);
  protected readonly contactSelected = signal<ResidencyRecord | null>(null);
  protected contactDraft: ResidencyBody = {};
  protected readonly adviserCandidates = signal<ResidencyCandidate[]>([]);
  protected readonly projectContacts = signal<ResidencyRecord[]>([]);
  protected readonly projectContactsNext = signal<string | null>(null);
  protected adviserSearch = '';
  protected adviserType: 'internal' | 'external' = 'internal';
  protected adviserReference = '';
  protected adviserRole: 'PRINCIPAL' | 'COLABORADOR' = 'PRINCIPAL';
  protected adviserStart = '';
  protected adviserEnd = '';
  protected assignmentEnds: Record<string, string> = {};
  private readonly keys = new Map<string, { signature: string; value: string }>();

  ngOnInit(): void {
    this.work.run(this.client.dashboard(), response => {
      this.ownResident.set(response.data.resident);
      this.load();
    });
  }

  protected global(permission: string): boolean {
    return this.work.auth.identity()?.scopes.includes(permission + ':global') ?? false;
  }

  protected canCreate(): boolean {
    switch (this.kind()) {
      case 'residents': return this.work.can('residencies.residents.enroll');
      case 'companies': return this.work.can('residencies.companies.create');
      case 'projects': return this.work.can('residencies.projects.manage') && (this.global('residencies.projects.manage') || this.ownResident()?.status === 'ACTIVO');
      default: return this.work.can('residencies.catalogs.manage');
    }
  }

  protected canEdit(): boolean {
    const record = this.selected();
    if (!record) return this.canCreate();
    switch (this.kind()) {
      case 'residents': return this.work.can('residencies.profile.manage');
      case 'companies': return record.canManage === true;
      case 'projects': return this.work.can('residencies.projects.manage') && record.status === 'BORRADOR' && this.selectedPeriodActive();
      default: return this.work.can('residencies.catalogs.manage');
    }
  }

  protected selectedPeriodActive(): boolean {
    const record = this.selected();
    return !record || this.referenceData['periodId']?.some(option => option.id === record.periodId && option.status === 'ACTIVO') === true;
  }

  protected canAssign(): boolean {
    return this.kind() === 'projects' && this.selected()?.status === 'BORRADOR' && this.selectedPeriodActive() && this.work.can('residencies.advisers.assign');
  }

  protected changeKind(kind: ResidencyKind): void {
    this.kind.set(kind); this.search = ''; this.records.set([]); this.selected.set(null); this.notice.set('');
    this.referenceData = {}; this.referenceNext = {}; this.referenceSearch = {}; this.contacts.set([]); this.projectContacts.set([]);
    this.load();
  }

  protected load(append = false): void {
    if (this.kind() === 'residents' && !this.global('residencies.files.read')) {
      this.work.run(this.client.dashboard(), response => {
        this.ownResident.set(response.data.resident); this.records.set(response.data.resident ? [response.data.resident] : []); this.nextCursor.set(null);
        if (response.data.resident) this.apply(response.data.resident); else this.newRecord();
      });
      return;
    }
    if (this.kind() === 'projects' && !this.work.can('residencies.files.read')) { this.records.set([]); this.newRecord(); return; }
    const kind = this.kind();
    this.work.run(this.client.list(kind, { q: this.search || undefined, pageSize: 25, cursor: append ? this.nextCursor() ?? undefined : undefined }), response => {
      if (kind !== this.kind()) return;
      this.records.set(append ? [...this.records(), ...response.data.items] : response.data.items); this.nextCursor.set(response.data.nextCursor);
      if (!this.selected()) this.newRecord();
    });
  }

  protected newRecord(): void {
    this.selected.set(null); this.draft = { active: true, status: this.kind() === 'periods' ? 'PLANIFICADO' : 'ACTIVO', durationMonths: 5, vacancies: 1 };
    if (this.kind() === 'projects' && !this.global('residencies.projects.manage')) {
      this.draft['residentId'] = this.ownResident()?.id ?? ''; this.draft['programId'] = this.ownResident()?.programId ?? '';
    }
    this.contactSelected.set(null); this.contacts.set([]); this.projectContacts.set([]); this.assignmentEnds = {};
    this.keys.delete('record'); this.loadReferences();
  }

  protected open(record: ResidencyRecord): void {
    const kind = this.kind();
    this.work.run(this.client.get(kind, record.id), response => { if (kind === this.kind()) this.apply(response.data); });
  }

  private apply(record: ResidencyRecord): void {
    this.selected.set(record); this.draft = {};
    for (const field of this.definitions[this.kind()].fields) {
      if (!field.createOnly) this.draft[field.key] = (record[field.key as keyof ResidencyRecord] ?? (field.type === 'checkbox' ? false : '')) as string | number | boolean | null;
    }
    this.loadReferences();
    if (this.kind() === 'companies') {
      this.contacts.set([]); this.contactsNext.set(null); this.newContact();
      if (record.canReadContacts) this.loadContacts();
    }
    if (this.kind() === 'projects') {
      this.adviserStart = record.startsOn ?? ''; this.adviserEnd = ''; this.adviserReference = ''; this.assignmentEnds = {};
      if (this.work.can('residencies.advisers.assign')) { this.loadAdvisers(); this.loadProjectContacts(); }
    }
  }

  private loadReferences(): void {
    for (const field of this.fields()) if (field.reference) this.loadReference(field);
  }

  protected loadReference(field: Field, append = false): void {
    const reference = field.reference;
    if (!reference) return;
    const q = this.referenceSearch[field.key] || undefined;
    if (reference === 'identities') {
      if (!this.work.can('residencies.residents.enroll')) return;
      this.work.run(this.client.candidates('identities', q ?? ''), response => { this.referenceData[field.key] = response.data.items.filter(item => item.status === 'ACTIVA').map(item => ({ id: item.id, name: item.displayName })); });
      return;
    }
    if (reference === 'residents' && !this.global('residencies.files.read')) {
      const resident = this.ownResident(); this.referenceData[field.key] = resident ? [{ id: resident.id, name: resident.name ?? resident.controlNumber ?? resident.id, programId: resident.programId }] : []; return;
    }
    const kind = this.kind(); const selected = this.selected()?.id;
    this.work.run(this.client.list(reference, { q, pageSize: 100, active: 1, cursor: append ? this.referenceNext[field.key] ?? undefined : undefined }), response => {
      if (this.kind() !== kind || this.selected()?.id !== selected) return;
      const options = response.data.items.map(item => ({ id: item.id, name: item.name ?? item.code ?? item.id, programId: item.programId, status: item.status }));
      this.referenceData[field.key] = append ? [...(this.referenceData[field.key] ?? []), ...options] : options; this.referenceNext[field.key] = response.data.nextCursor;
      const current = this.draft[field.key];
      if (typeof current === 'string' && current && !this.referenceData[field.key].some(option => option.id === current)) {
        this.work.run(this.client.get(reference, current), one => { if (this.kind() === kind && this.selected()?.id === selected) this.referenceData[field.key] = [...this.referenceData[field.key], { id: one.data.id, name: one.data.name ?? current, programId: one.data.programId, status: one.data.status }]; });
      }
    });
  }

  protected referenceChanged(field: Field, value: string): void {
    this.draft[field.key] = value;
    if (field.key === 'residentId') this.draft['programId'] = this.referenceData[field.key]?.find(option => option.id === value)?.programId ?? '';
  }

  protected disabledField(field: Field): boolean {
    return !!this.selected() && (field.immutable === true || (field.key === 'programId' && this.kind() === 'residents' && !this.work.can('residencies.residents.enroll')));
  }

  protected label(field: Field, record: ResidencyRecord): string {
    const value = record[field.key as keyof ResidencyRecord];
    if (typeof value === 'boolean') return value ? 'Sí' : 'No';
    if (field.reference && typeof value === 'string') return this.referenceData[field.key]?.find(option => option.id === value)?.name ?? value;
    return value === null || value === undefined || value === '' ? 'Sin registrar' : String(value);
  }

  private body(): ResidencyBody {
    const body: ResidencyBody = {};
    for (const field of this.fields()) {
      const value = this.draft[field.key];
      body[field.key] = field.type === 'checkbox' ? Boolean(value) : field.optional && (value === '' || value === undefined) ? null : field.type === 'number' ? Number(value) : value ?? '';
    }
    return body;
  }

  private key(slot: string, context: string, body: ResidencyBody): string {
    const signature = JSON.stringify([context, body]); const existing = this.keys.get(slot);
    if (existing?.signature === signature) return existing.value;
    const value = crypto.randomUUID(); this.keys.set(slot, { signature, value }); return value;
  }

  protected save(form?: NgForm): void {
    if (form?.invalid) {
      form.control.markAllAsTouched();
      this.work.invalidFields.set(Object.keys(form.controls).filter(key => form.controls[key].invalid));
      this.work.message.set('Revisa los campos indicados antes de guardar.');
      return;
    }
    const body = this.body(); const kind = this.kind(); const selected = this.selected(); const key = this.key('record', kind, body);
    this.notice.set('');
    this.work.run(this.work.csrf(token => this.client.save(kind, body, selected, key, token)), response => {
      this.keys.delete('record'); this.notice.set('Cambios guardados y auditados.'); this.open(response.data); this.load();
    }, () => { this.notice.set('Hay un conflicto. Se recargaron los datos; revisa la versión y las referencias antes de guardar.'); if (selected) this.open(selected); else this.loadReferences(); });
  }

  protected newContact(): void { this.contactSelected.set(null); this.contactDraft = { givenNames: '', firstSurname: '', active: true }; this.keys.delete('contact'); }
  protected editContact(contact: ResidencyRecord): void {
    this.contactSelected.set(contact); this.contactDraft = { givenNames: contact.givenNames ?? '', firstSurname: contact.firstSurname ?? '', secondSurname: contact.secondSurname ?? '', position: contact.position ?? '', degree: contact.degree ?? '', email: contact.email ?? '', phone: contact.phone ?? '', active: contact.active ?? true };
  }
  protected loadContacts(append = false): void {
    const company = this.selected(); if (!company) return;
    this.work.run(this.client.contacts(company.id, { cursor: append ? this.contactsNext() ?? undefined : undefined }), response => { if (this.selected()?.id === company.id) { this.contacts.set(append ? [...this.contacts(), ...response.data.items] : response.data.items); this.contactsNext.set(response.data.nextCursor); } });
  }
  protected saveContact(): void {
    const company = this.selected(); if (!company) return;
    const body = { ...this.contactDraft }; for (const field of ['secondSurname', 'position', 'degree', 'email', 'phone']) if (!body[field]) body[field] = null;
    const key = this.key('contact', company.id, body); const selected = this.contactSelected();
    this.work.run(this.work.csrf(token => this.client.saveContact(company.id, body, selected, key, token)), response => { this.editContact(response.data); this.keys.delete('contact'); this.loadContacts(); this.notice.set('Contacto guardado. Las instantáneas de proyectos anteriores se conservan.'); }, () => { this.notice.set('El contacto cambió. Selecciónalo de nuevo antes de editar.'); this.newContact(); this.loadContacts(); });
  }

  protected loadAdvisers(): void { this.work.run(this.client.candidates('advisers', this.adviserSearch), response => this.adviserCandidates.set(response.data.items)); }
  protected loadProjectContacts(append = false): void {
    const project = this.selected(); if (!project?.companyId) return;
    this.work.run(this.client.contacts(project.companyId, { active: 1, cursor: append ? this.projectContactsNext() ?? undefined : undefined }), response => { if (this.selected()?.id === project.id) { this.projectContacts.set(append ? [...this.projectContacts(), ...response.data.items] : response.data.items); this.projectContactsNext.set(response.data.nextCursor); } });
  }
  protected assign(): void {
    const project = this.selected(); if (!project) return;
    const body: ResidencyBody = { [this.adviserType === 'internal' ? 'employeeId' : 'contactId']: this.adviserReference, roleCode: this.adviserRole, startsOn: this.adviserStart, endsOn: this.adviserEnd || null };
    const key = this.key('adviser', project.id + ':' + project.version + ':' + this.adviserType, body);
    this.work.run(this.work.csrf(token => this.client.assign(this.adviserType, project, body, key, token)), () => { this.keys.delete('adviser'); this.notice.set('Asesor asignado con instantánea institucional.'); this.open(project); }, () => { this.notice.set('El proyecto o las asignaciones cambiaron. Revisa los datos recargados.'); this.open(project); });
  }
  protected endAssignment(type: 'internal' | 'external', assignment: ResidencyRecord): void {
    const project = this.selected(); const date = this.assignmentEnds[assignment.id]; if (!project || !date) return;
    this.work.run(this.work.csrf(token => this.client.end(type, project, assignment.id, date, token)), () => { this.notice.set('Asignación finalizada; instantánea conservada.'); this.open(project); }, () => { this.notice.set('La versión del proyecto cambió. Revisa las asignaciones recargadas.'); this.open(project); });
  }
}
