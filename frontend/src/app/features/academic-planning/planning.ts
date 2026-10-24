import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { listPlanningResponsibles } from '../../api/functions';
import { PlanningRecord } from '../../api/models';
import { WorkspaceService } from '../../core/auth/workspace.service';
import { PlanningBody, PlanningClient, PlanningKind } from './planning-client';

interface Field { key: string; label: string; type?: 'checkbox' | 'date' | 'datetime-local' | 'number' | 'reference' | 'status'; optional?: boolean; reference?: PlanningKind | 'responsibles'; }
const name: Field = { key: 'name', label: 'Nombre' };
const code: Field = { key: 'code', label: 'Código' };
const active: Field = { key: 'active', label: 'Activo', type: 'checkbox' };
const career: Field = { key: 'careerId', label: 'Carrera', type: 'reference', reference: 'careers' };
const definitions: Record<PlanningKind, { label: string; fields: Field[] }> = {
  careers: { label: 'Carreras', fields: [code, name, { key: 'shortName', label: 'Nombre corto', optional: true }, active] },
  specialties: { label: 'Especialidades', fields: [career, code, name, active] },
  subjects: { label: 'Asignaturas', fields: [code, name, { key: 'satca', label: 'SATCA (horas teóricas-prácticas-créditos)', optional: true }, active] },
  curricula: { label: 'Retículas', fields: [career, { key: 'specialtyId', label: 'Especialidad', type: 'reference', reference: 'specialties', optional: true }, { key: 'subjectId', label: 'Asignatura', type: 'reference', reference: 'subjects' }, active] },
  groups: { label: 'Grupos', fields: [career, { key: 'semesterId', label: 'Semestre', type: 'reference', reference: 'semesters' }, name, active] },
  'holiday-types': { label: 'Tipos de día inhábil', fields: [code, name, active] },
  semesters: { label: 'Semestres', fields: [code, name, { key: 'startsOn', label: 'Fecha de inicio', type: 'date' }, { key: 'endsOn', label: 'Fecha de fin', type: 'date' }] },
  laboratories: { label: 'Laboratorios', fields: [code, name, { ...career, optional: true }, { key: 'capacity', label: 'Capacidad', type: 'number' }, { key: 'responsibleEmployeeId', label: 'Empleado responsable', type: 'reference', reference: 'responsibles', optional: true }, { key: 'status', label: 'Estado', type: 'status' }] },
  holidays: { label: 'Días inhábiles', fields: [{ key: 'holidayTypeId', label: 'Tipo de día inhábil', type: 'reference', reference: 'holiday-types' }, { key: 'laboratoryId', label: 'Laboratorio (vacío: todos)', type: 'reference', reference: 'laboratories', optional: true }, name, { key: 'startsAt', label: 'Inicio (hora local)', type: 'datetime-local' }, { key: 'endsAt', label: 'Fin exclusivo (hora local)', type: 'datetime-local' }, active] },
};

@Component({
  selector: 'app-planning',
  imports: [FormsModule, RouterLink],
  providers: [WorkspaceService, PlanningClient],
  template: `
    <h2>Planificación académica</h2>
    <p><a routerLink="/academic-planning/calendar">Horarios y disponibilidad</a></p>
    <p><a routerLink="/dashboard">Volver a tu sesión</a></p>
    <p>Consulta los catálogos institucionales. Los cambios se guardan con control de versión.</p>
    @if (work.message()) { <p role="alert">{{ work.message() }}</p> }
    <fieldset [disabled]="work.busy()">
      <legend>Consulta</legend>
      <label>Catálogo <select [ngModel]="kind()" (ngModelChange)="changeKind($event)">
        @for (choice of kinds; track choice) { <option [value]="choice">{{ definitions[choice].label }}</option> }
      </select></label>
      <form (ngSubmit)="load()"><label>Buscar por nombre <input name="search" [(ngModel)]="search" maxlength="100"></label><button>Buscar</button></form>
      <ul>@for (record of records(); track record.id) {
        <li><button type="button" (click)="open(record)">{{ record.code ? record.code + ' · ' : '' }}{{ record.name }}</button> · {{ record.status ?? (record.active ? 'Activo' : 'Inactivo') }}
          @if (record.startsOn) { <span> · {{ record.startsOn }} a {{ record.endsOn }}</span> }
          @if (record.capacity) { <span> · Capacidad: {{ record.capacity }}</span> }
          @if (record.startsAt) { <span> · {{ record.startsAt }} a {{ record.endsAt }}</span> }
        </li>
      } @empty { <li>No hay registros en esta consulta.</li> }</ul>
      @if (nextCursor()) { <button type="button" (click)="load(true)">Cargar más registros</button> }
      @if (canEdit()) { <button type="button" (click)="newRecord()">Nuevo registro</button> }
    </fieldset>
    @if (selected(); as record) { <p>Seleccionado: {{ record.name }} · versión {{ record.version }} · {{ record.status ?? (record.active ? 'Activo' : 'Inactivo') }}</p> }
    @if (canEdit()) {
      <fieldset [disabled]="work.busy() || locked()">
        <legend>{{ selected() ? 'Editar' : 'Registrar' }} · {{ definitions[kind()].label }}</legend>
        @if (kind() === 'semesters') { <p>Se crea planificado. Para activar otro semestre debes cerrar primero el activo. Los semestres cerrados o archivados conservan sus datos.</p> }
        <form (ngSubmit)="save()">
          @for (field of fields(); track field.key) {
            <label>{{ field.label }}
              @if (field.type === 'checkbox') { <input type="checkbox" [name]="field.key" [ngModel]="draft[field.key]" (ngModelChange)="draft[field.key] = $event"> }
              @else if (field.type === 'reference') {
                <select [name]="field.key" [ngModel]="draft[field.key]" (ngModelChange)="referenceChanged(field.key, $event)" [required]="!field.optional">
                  <option value="">{{ field.optional ? 'Sin asignar' : 'Seleccionar' }}</option>
                  @for (option of optionsFor(field); track option.id) { <option [value]="option.id">{{ option.name }}</option> }
                </select>
              } @else if (field.type === 'status') {
                <select [name]="field.key" [(ngModel)]="draft[field.key]"><option>ACTIVO</option><option>MANTENIMIENTO</option><option>INACTIVO</option><option>ARCHIVADO</option></select>
              } @else {
                <input [name]="field.key" [type]="field.type ?? 'text'" [(ngModel)]="draft[field.key]" [required]="!field.optional" [attr.maxlength]="field.key === 'code' ? 40 : field.key === 'shortName' ? 80 : 100" [attr.min]="field.type === 'number' ? 1 : null" [attr.max]="field.type === 'number' ? 65535 : null" [attr.step]="field.type === 'datetime-local' ? 1 : null" [attr.pattern]="field.key === 'code' ? '[A-Z0-9][A-Z0-9._-]{0,39}' : null">
              }
            </label>
            @if (field.type === 'reference') {
              <label>Buscar opciones de {{ field.label }} <input [name]="field.key + '-search'" [(ngModel)]="referenceSearch[field.key]" maxlength="100"></label>
              <button type="button" (click)="loadReference(field)">Buscar opciones</button>
              @if (referenceNext[field.key]) { <button type="button" (click)="loadReference(field, true)">Más opciones de {{ field.label }}</button> }
            }
          }
          <button>{{ selected() ? 'Guardar cambios' : 'Registrar' }}</button>
        </form>
      </fieldset>
      @if (kind() === 'semesters' && selected(); as semester) {
        <fieldset [disabled]="work.busy()"><legend>Estado del semestre</legend>
          @if (semester.status === 'PLANIFICADO') { <button type="button" (click)="transition('activate')">Activar semestre</button> }
          @if (semester.status === 'ACTIVO') { <button type="button" (click)="transition('close')">Cerrar semestre</button> }
          @if (semester.status === 'PLANIFICADO' || semester.status === 'CERRADO') { <button type="button" (click)="transition('archive')">Archivar semestre</button> }
        </fieldset>
      }
    }
  `,
})
export class Planning {
  protected readonly definitions = definitions;
  protected readonly kinds = Object.keys(definitions) as PlanningKind[];
  protected readonly work = inject(WorkspaceService);
  private readonly client = inject(PlanningClient);
  protected readonly kind = signal<PlanningKind>('careers');
  protected readonly records = signal<PlanningRecord[]>([]);
  protected readonly selected = signal<PlanningRecord | null>(null);
  protected readonly nextCursor = signal<string | null>(null);
  protected readonly fields = computed(() => definitions[this.kind()].fields);
  protected readonly canEdit = computed(() => this.work.can(['semesters','laboratories','holidays'].includes(this.kind()) ? 'planning.manage' : 'planning.catalogs.manage'));
  protected readonly locked = computed(() => this.kind() === 'semesters' && ['CERRADO','ARCHIVADO'].includes(this.selected()?.status ?? ''));
  protected search = '';
  protected draft: PlanningBody = {};
  protected referenceSearch: Record<string,string> = {};
  protected referenceNext: Record<string,string | null> = {};
  private references: Record<string,{ id: string; name: string; careerId?: string | null }[]> = {};
  private retry: { fingerprint: string; key: string } | null = null;

  constructor() { this.newRecord(); this.load(); }

  protected changeKind(kind: PlanningKind): void {
    this.kind.set(kind); this.search = ''; this.nextCursor.set(null); this.records.set([]); this.references = {}; this.referenceSearch = {}; this.referenceNext = {};
    this.newRecord(); this.load();
  }

  protected load(more = false): void {
    const query = { pageSize: 25, ...(this.search ? { q: this.search } : {}), ...(more && this.nextCursor() ? { cursor: this.nextCursor()! } : {}) };
    this.work.run(this.client.list(this.kind(), query), result => {
      this.records.update(old => more ? [...old, ...result.data.items] : result.data.items);
      this.nextCursor.set(result.data.nextCursor);
    });
  }

  protected newRecord(): void {
    this.selected.set(null); this.retry = null;
    this.draft = Object.fromEntries(this.fields().map(field => [field.key, field.type === 'checkbox' ? true : field.type === 'number' ? 1 : field.type === 'status' ? 'ACTIVO' : '']));
    if (this.canEdit()) { this.fields().filter(field => field.type === 'reference').forEach(field => this.loadReference(field)); }
  }

  protected open(record: PlanningRecord): void {
    this.work.run(this.client.get(this.kind(), record.id), result => this.accept(result.data));
  }

  private accept(record: PlanningRecord): void {
    this.selected.set(record);
    this.retry = null;
    const values = record as unknown as PlanningBody;
    this.draft = Object.fromEntries(this.fields().map(field => {
      let value = values[field.key] ?? '';
      if (field.type === 'datetime-local' && typeof value === 'string' && value) {
        const date = new Date(value); value = new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0,19);
      }
      return [field.key, value];
    }));
  }

  protected optionsFor(field: Field): { id: string; name: string }[] {
    const options = this.references[field.key] ?? [];
    return field.key === 'specialtyId' && this.draft['careerId'] ? options.filter(option => option.careerId === this.draft['careerId']) : options;
  }

  protected referenceChanged(key: string, value: string): void {
    this.draft[key] = value;
    if (key === 'careerId' && this.kind() === 'curricula') { this.draft['specialtyId'] = ''; }
  }

  protected loadReference(field: Field, more = false): void {
    const q = this.referenceSearch[field.key] || undefined;
    if (field.reference === 'responsibles') {
      this.work.run(this.work.api.invoke(listPlanningResponsibles, { q }), result => {
        this.references[field.key] = result.data.map(value => ({ id: value.id, name: value.displayName }));
      });
      return;
    }
    if (!field.reference) return;
    this.work.run(this.client.list(field.reference, { pageSize: 100, q, ...(more && this.referenceNext[field.key] ? { cursor: this.referenceNext[field.key]! } : {}) }), result => {
      const options = result.data.items.filter(value => field.reference === 'semesters' ? ['PLANIFICADO','ACTIVO'].includes(value.status ?? '') : field.reference === 'laboratories' ? value.status !== 'ARCHIVADO' : value.active);
      this.references[field.key] = [...(more ? this.references[field.key] ?? [] : []), ...options];
      this.referenceNext[field.key] = result.data.nextCursor;
    });
  }

  private key(fingerprint: string): string {
    if (this.retry?.fingerprint !== fingerprint) this.retry = { fingerprint, key: crypto.randomUUID() };
    return this.retry.key;
  }

  protected save(): void {
    const body = Object.fromEntries(this.fields().map(field => {
      let value = this.draft[field.key];
      if (field.optional && value === '') value = null;
      if (field.type === 'number') value = Number(value);
      if (field.type === 'datetime-local' && typeof value === 'string') value = new Date(value).toISOString();
      return [field.key, value];
    })) as PlanningBody;
    const selected = this.selected();
    const key = this.key(JSON.stringify([this.kind(), selected?.id, selected?.version, body]));
    this.work.run(this.work.csrf(() => this.client.save(this.kind(), body, selected, key)), result => { this.accept(result.data); this.load(); }, () => { if (selected) this.open(selected); });
  }

  protected transition(action: 'activate' | 'close' | 'archive'): void {
    const record = this.selected(); if (!record) return;
    const key = this.key(JSON.stringify([record.id, record.version, action]));
    this.work.run(this.work.csrf(() => this.client.transition(action, record, key)), result => { this.accept(result.data); this.load(); }, () => this.open(record));
  }
}
