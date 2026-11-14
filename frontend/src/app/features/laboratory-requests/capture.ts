import { Component, inject, signal } from '@angular/core';
import { FormsModule, NgForm } from '@angular/forms';
import { RouterLink } from '@angular/router';
import {
  createLaboratoryRequest, getLaboratoryRequest, listLaboratoryRequests, listPlanningCatalog,
  listPlanningLaboratory, quoteLaboratoryAvailability, submitLaboratoryRequest, updateLaboratoryRequest,
} from '../../api/functions';
import { LaboratoryQuoteResponse, LaboratoryRequestInput, LaboratoryRequestRecord, LaboratoryVariousDetail, PlanningRecord } from '../../api/models';
import { WorkspaceService } from '../../core/auth/workspace.service';

type Kind = 'laboratories' | 'careers' | 'subjects' | 'groups';
type RequestType = LaboratoryRequestInput['type'];

@Component({
  selector: 'app-laboratory-request-capture',
  imports: [FormsModule, RouterLink],
  providers: [WorkspaceService],
  templateUrl: './capture.html',
})
export class LaboratoryRequestCapture {
  protected readonly work = inject(WorkspaceService);
  protected readonly records = signal<LaboratoryRequestRecord[]>([]);
  protected readonly next = signal<string | null>(null);
  protected readonly selected = signal<LaboratoryRequestRecord | null>(null);
  protected readonly quote = signal<LaboratoryQuoteResponse['data'] | null>(null);
  protected readonly notice = signal('');
  protected readonly options = signal<Record<Kind, PlanningRecord[]>>({laboratories: [], careers: [], subjects: [], groups: []});
  protected readonly cursors = signal<Record<Kind, string | null>>({laboratories: null, careers: null, subjects: null, groups: null});
  protected readonly labels: Record<RequestType, string> = {PRACTICA: 'Práctica', VARIA: 'Varia', EXTRAORDINARIA: 'Extraordinaria'};
  protected laboratory = '';
  protected day = '';
  protected start = '07:00';
  protected end = '08:00';
  protected type: RequestType = 'PRACTICA';
  protected title = '';
  protected purpose = '';
  protected attendees = 1;
  protected supplies = '';
  protected practice = {careerId: '', subjectId: '', groupId: '', name: '', number: ''};
  protected various: LaboratoryVariousDetail = {useCode: 'PROYECTO', description: ''};
  protected extraordinary = {justification: ''};
  protected step: 1 | 2 = 1;
  protected readonly catalogLabels = {careers: 'carreras', subjects: 'asignaturas', groups: 'grupos'} as const;
  protected readonly catalogKinds = ['careers', 'subjects', 'groups'] as const;
  private savedState = '';
  private retry: {fingerprint: string; key: string} | null = null;
  private readonly zone = 'America/Mexico_City';
  private readonly display = new Intl.DateTimeFormat('es-MX', {timeZone: this.zone, dateStyle: 'medium', timeStyle: 'short'});
  private readonly local = new Intl.DateTimeFormat('sv-SE', {timeZone: this.zone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'});
  private readonly offset = new Intl.DateTimeFormat('en-US', {timeZone: this.zone, timeZoneName: 'longOffset'});

  constructor() {
    this.type = this.allowedTypes()[0] ?? 'PRACTICA';
    this.loadOptions('laboratories');
    if (this.allowedTypes().includes('PRACTICA')) {
      for (const kind of this.catalogKinds) this.loadOptions(kind);
    }
    this.loadList();
  }

  protected allowedTypes(): RequestType[] {
    const result: RequestType[] = [];
    if (this.work.can('laboratorios.solicitudes.crear')) result.push('PRACTICA', 'VARIA');
    if (this.work.can('laboratorios.solicitudes.extraordinarias.crear')) result.push('EXTRAORDINARIA');
    return result;
  }

  protected editable(): boolean {
    const selected = this.selected();
    return (!selected || selected.status === 'BORRADOR') && this.allowedTypes().includes(this.type);
  }

  protected hasUnsavedChanges(): boolean {
    return this.savedState !== this.formState();
  }

  protected displayDate(value: string): string {
    return this.display.format(new Date(value));
  }

  protected loadOptions(kind: Kind, more = false): void {
    const cursor = more ? this.cursors()[kind] ?? undefined : undefined;
    const params = {pageSize: 100, active: 1 as const, cursor};
    const request = kind === 'laboratories' ? this.work.api.invoke(listPlanningLaboratory, params)
      : this.work.api.invoke(listPlanningCatalog, {...params, catalog: kind});
    this.work.run(request, response => {
      this.options.update(state => ({...state, [kind]: more ? [...state[kind], ...response.data.items] : response.data.items}));
      this.cursors.update(state => ({...state, [kind]: response.data.nextCursor}));
    });
  }

  protected loadList(more = false): void {
    this.work.run(this.work.api.invoke(listLaboratoryRequests, {cursor: more ? this.next() ?? undefined : undefined}), response => {
      this.records.set(more ? [...this.records(), ...response.data.items] : response.data.items);
      this.next.set(response.data.nextCursor);
    });
  }

  protected open(id: string): void {
    this.work.run(this.work.api.invoke(getLaboratoryRequest, {record: id}), response => this.populate(response.data));
  }

  protected reset(): void {
    this.selected.set(null);
    this.step = 1;
    this.title = '';
    this.purpose = '';
    this.attendees = 1;
    this.supplies = '';
    this.type = this.allowedTypes()[0] ?? 'PRACTICA';
    this.clearDetails();
    this.rangeChanged();
    this.retry = null;
    this.notice.set('');
  }

  protected rangeChanged(): void {
    this.quote.set(null);
  }

  protected clearDetails(): void {
    this.practice = {careerId: '', subjectId: '', groupId: '', name: '', number: ''};
    this.various = {useCode: 'PROYECTO', description: ''};
    this.extraordinary = {justification: ''};
  }

  protected checkAvailability(): void {
    const body = this.range();
    if (!body) return;
    this.work.run(this.work.csrf(token => this.work.api.invoke(quoteLaboratoryAvailability, {body, 'X-XSRF-TOKEN': token})), response => {
      this.quote.set(response.data);
    });
  }

  protected save(form: NgForm): void {
    if (form.invalid || !this.editable()) return;
    const range = this.range();
    if (!range) return;
    const shared = {...range, title: this.title, purpose: this.purpose, attendees: this.attendees, supplies: this.supplies || null};
    const body: LaboratoryRequestInput = this.type === 'PRACTICA'
      ? {...shared, type: 'PRACTICA', practice: {...this.practice, number: this.practice.number || null}}
      : this.type === 'VARIA' ? {...shared, type: 'VARIA', various: {...this.various}}
      : {...shared, type: 'EXTRAORDINARIA', extraordinary: {...this.extraordinary}};
    const record = this.selected();
    const key = record ? null : this.idempotency(JSON.stringify(body));
    this.work.run(this.work.csrf(token => record
      ? this.work.api.invoke(updateLaboratoryRequest, {record: record.id, body, 'If-Match': '"' + record.version + '"', 'X-XSRF-TOKEN': token})
      : this.work.api.invoke(createLaboratoryRequest, {body, 'Idempotency-Key': key!, 'X-XSRF-TOKEN': token})),
    response => {
      this.populate(response.data);
      this.notice.set('Borrador guardado. Puedes revisarlo y enviarlo cuando esté listo.');
      this.loadList();
    });
  }

  protected submit(): void {
    const row = this.selected();
    if (!row || row.status !== 'BORRADOR' || !this.editable() || this.hasUnsavedChanges()) return;
    const key = this.idempotency(row.id + ':' + row.version);
    this.work.run(this.work.csrf(token => this.work.api.invoke(submitLaboratoryRequest, {
      record: row.id, 'If-Match': '"' + row.version + '"', 'Idempotency-Key': key, 'X-XSRF-TOKEN': token,
    })), response => {
      this.populate(response.data);
      this.notice.set('Solicitud enviada y pendiente de revisión. El laboratorio todavía no está reservado.');
      this.loadList();
    });
  }

  private populate(row: LaboratoryRequestRecord): void {
    this.selected.set(row);
    this.type = row.type;
    this.laboratory = row.laboratoryId;
    const [day, start] = this.local.format(new Date(row.startAt)).split(' ');
    this.day = day;
    this.start = start;
    this.end = this.local.format(new Date(row.endAt)).split(' ')[1];
    this.title = row.title;
    this.purpose = row.purpose;
    this.attendees = row.attendees;
    this.supplies = row.supplies ?? '';
    this.clearDetails();
    if (row.type === 'PRACTICA') this.practice = {...row.practice, number: row.practice.number ?? ''};
    if (row.type === 'VARIA') this.various = {...row.various};
    if (row.type === 'EXTRAORDINARIA') this.extraordinary = {...row.extraordinary};
    this.step = 2;
    this.retry = null;
    this.savedState = this.formState();
    this.quote.set(null);
    this.notice.set('');
  }

  private formState(): string {
    return JSON.stringify([this.laboratory, this.day, this.start, this.end, this.type, this.title, this.purpose,
      this.attendees, this.supplies, this.practice, this.various, this.extraordinary]);
  }

  private range(): {laboratoryId: string; startAt: string; endAt: string} | null {
    if (!this.laboratory || !this.day || !this.start || !this.end) return null;
    const offset = (time: string) => {
      const local = this.day + 'T' + time + ':00';
      const zone = this.offset.formatToParts(new Date(local + 'Z')).find(part => part.type === 'timeZoneName')?.value;
      return local + (zone?.replace('GMT', '') || 'Z');
    };
    return {laboratoryId: this.laboratory, startAt: offset(this.start), endAt: offset(this.end)};
  }

  private idempotency(fingerprint: string): string {
    if (this.retry?.fingerprint !== fingerprint) this.retry = {fingerprint, key: crypto.randomUUID()};
    return this.retry.key;
  }
}
