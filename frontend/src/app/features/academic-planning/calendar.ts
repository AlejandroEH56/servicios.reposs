import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { getPlanningAvailability, listPlanningLaboratory, listPlanningSchedules, listPlanningSemester, publishPlanningSchedule, updatePlanningSchedule } from '../../api/functions';
import { PlanningAvailabilityResponse, PlanningRecord, PlanningScheduleRecord, PublishPlanningScheduleInput } from '../../api/models';
import { WorkspaceService } from '../../core/auth/workspace.service';

@Component({
  selector: 'app-planning-calendar',
  imports: [FormsModule, RouterLink],
  providers: [WorkspaceService],
  template: `
    <h2>Horarios y disponibilidad</h2>
    <p><a routerLink="/academic-planning">Volver a planificación académica</a></p>
    <p>Franjas semanales en America/Mexico_City, de 07:00 a 20:00. Los días sin franja publicada permanecen cerrados.</p>
    @if (work.message()) { <p role="alert">{{ work.message() }}</p> }
    @if (conflictNotice()) { <p role="alert">{{ conflictNotice() }}</p> }
    <fieldset [disabled]="work.busy()"><legend>Laboratorio y semestre</legend>
      <label for="calendar-lab">Laboratorio</label>
      <select id="calendar-lab" [(ngModel)]="laboratory" (ngModelChange)="contextChanged()">
        @for (lab of laboratories(); track lab.id) { <option [value]="lab.id">{{ lab.code }} · {{ lab.name }} · {{ lab.status }}</option> }
      </select>
      <label for="calendar-lab-search">Buscar laboratorio</label><input id="calendar-lab-search" [(ngModel)]="labSearch" maxlength="100">
      <button type="button" (click)="loadOptions('laboratories')">Buscar laboratorio</button>
      @if (labCursor()) { <button type="button" (click)="loadOptions('laboratories', true)">Más laboratorios</button> }
      <label for="calendar-semester">Semestre</label>
      <select id="calendar-semester" [(ngModel)]="semester" (ngModelChange)="contextChanged()">
        @for (item of semesters(); track item.id) { <option [value]="item.id">{{ item.name }} · {{ item.status }}</option> }
      </select>
      <label for="calendar-semester-search">Buscar semestre</label><input id="calendar-semester-search" [(ngModel)]="semesterSearch" maxlength="100">
      <button type="button" (click)="loadOptions('semesters')">Buscar semestre</button>
      @if (semesterCursor()) { <button type="button" (click)="loadOptions('semesters', true)">Más semestres</button> }
      <button type="button" (click)="loadSchedules()" [disabled]="!laboratory || !semester">Actualizar franjas</button>
      <p>Periodo del semestre seleccionado: {{ semesterDates() }}.</p>
    </fieldset>
    <ul aria-label="Franjas semanales">
      @for (slot of schedules(); track slot.id) {
        <li><button type="button" (click)="select(slot)" [disabled]="work.busy()">{{ dayName(slot.weekday) }} {{ slot.startsAtLocal }}–{{ slot.endsAtLocal }} · {{ slot.active ? 'Publicada' : 'Retirada' }}</button></li>
      } @empty { <li>No hay franjas en esta consulta.</li> }
    </ul>
    @if (nextCursor()) { <button type="button" (click)="loadSchedules(true)" [disabled]="work.busy()">Más franjas</button> }
    @if (selected(); as slot) { <p>Franja seleccionada · versión {{ slot.version }} · {{ slot.active ? 'Publicada' : 'Retirada' }}</p> }
    @if (canEdit()) {
      <fieldset [disabled]="work.busy()"><legend>{{ selected() ? 'Editar franja' : 'Publicar franja' }}</legend>
        <button type="button" (click)="newSlot()">Nueva franja</button>
        <form (ngSubmit)="save()">
          <label for="calendar-day">Día de la semana</label><select id="calendar-day" name="weekday" [(ngModel)]="weekday">
            @for (day of days; track day[0]) { <option [ngValue]="day[0]">{{ day[1] }}</option> }
          </select>
          <label for="calendar-start">Hora inicial institucional</label><input id="calendar-start" name="startsAtLocal" type="time" min="07:00" max="20:00" step="60" required [(ngModel)]="startsAtLocal">
          <label for="calendar-end">Hora final institucional</label><input id="calendar-end" name="endsAtLocal" type="time" min="07:00" max="20:00" step="60" required [(ngModel)]="endsAtLocal">
          @if (selected()) { <label for="calendar-active">Franja publicada</label><input id="calendar-active" name="active" type="checkbox" [(ngModel)]="active"> }
          <button>{{ selected() ? 'Guardar franja' : 'Publicar franja' }}</button>
        </form>
      </fieldset>
    }
    <h3>Disponibilidad del semestre activo</h3>
    <p>Consulta orientativa del calendario académico. Esta consulta no reserva el laboratorio.</p>
    @if (semesterActive()) {
      <fieldset [disabled]="work.busy()"><legend>Consultar intervalo, hasta 31 días</legend>
        <form (ngSubmit)="queryAvailability()">
          <label for="availability-start">Inicio (hora del equipo)</label><input id="availability-start" name="queryStart" type="datetime-local" step="1" required [(ngModel)]="queryStart">
          <label for="availability-end">Fin exclusivo (hora del equipo)</label><input id="availability-end" name="queryEnd" type="datetime-local" step="1" required [(ngModel)]="queryEnd">
          <button [disabled]="!laboratory">Consultar disponibilidad</button>
        </form>
      </fieldset>
    } @else { <p>Selecciona el semestre activo para consultar disponibilidad. Los semestres anteriores conservan sus franjas históricas.</p> }
    @if (availabilityResult(); as result) {
      <p role="status">{{ result.available ? 'El intervalo completo está disponible.' : 'El intervalo solicitado tiene restricciones.' }}</p>
      <p>Resultados en {{ result.timeZone }}. {{ reason(result.reasonCode) }}</p>
      <ul aria-label="Intervalos disponibles">@for (range of result.intervals; track range.startAt) {
        <li>{{ formatInstant(range.startAt) }} a {{ formatInstant(range.endAt) }} (fin exclusivo)</li>
      } @empty { <li>No hay intervalos disponibles.</li> }</ul>
    }
  `,
})
export class Calendar {
  protected readonly work = inject(WorkspaceService);
  protected readonly laboratories = signal<PlanningRecord[]>([]);
  protected readonly semesters = signal<PlanningRecord[]>([]);
  protected readonly schedules = signal<PlanningScheduleRecord[]>([]);
  protected readonly selected = signal<PlanningScheduleRecord | null>(null);
  protected readonly conflictNotice = signal('');
  protected readonly nextCursor = signal<string | null>(null);
  protected readonly labCursor = signal<string | null>(null);
  protected readonly semesterCursor = signal<string | null>(null);
  protected readonly availabilityResult = signal<PlanningAvailabilityResponse['data'] | null>(null);
  protected readonly days = [[1, 'Lunes'], [2, 'Martes'], [3, 'Miércoles'], [4, 'Jueves'], [5, 'Viernes'], [6, 'Sábado'], [7, 'Domingo']] as const;
  laboratory = '';
  semester = '';
  labSearch = '';
  semesterSearch = '';
  weekday = 1;
  startsAtLocal = '07:00';
  endsAtLocal = '08:00';
  active = true;
  queryStart = this.localInput(new Date());
  queryEnd = this.localInput(new Date(Date.now() + 3600000));
  private retry: { fingerprint: string; key: string } | null = null;

  constructor() {
    this.loadOptions('laboratories');
    this.loadOptions('semesters');
  }

  protected semesterActive(): boolean { return this.semesters().some(item => item.id === this.semester && item.status === 'ACTIVO'); }
  protected semesterDates(): string { const selected = this.semesters().find(item => item.id === this.semester); return selected?.startsOn && selected.endsOn ? selected.startsOn + ' a ' + selected.endsOn : 'sin selección'; }
  protected canEdit(): boolean { return this.work.can('planning.manage') && this.semesterActive() && this.laboratories().some(item => item.id === this.laboratory && item.status === 'ACTIVO'); }
  protected dayName(day: number): string { return this.days.find(item => item[0] === day)?.[1] ?? ''; }
  protected formatInstant(value: string): string { return new Intl.DateTimeFormat('es-MX', { timeZone: 'America/Mexico_City', dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)); }
  protected reason(code: PlanningAvailabilityResponse['data']['reasonCode']): string {
    return code === 'NO_ACTIVE_SEMESTER' ? 'No hay semestre activo.' : code === 'LABORATORY_UNAVAILABLE' ? 'El laboratorio no está activo.'
      : code === 'NO_SCHEDULE' ? 'No hay franjas publicadas para el semestre activo.' : code === 'OUTSIDE_AVAILABLE_INTERVAL' ? 'Revisa las franjas, las fechas del semestre y los intervalos inhábiles.' : '';
  }

  loadOptions(kind: 'laboratories' | 'semesters', append = false): void {
    const list = kind === 'laboratories' ? this.laboratories : this.semesters;
    const cursor = kind === 'laboratories' ? this.labCursor : this.semesterCursor;
    const query = { pageSize: 100, q: kind === 'laboratories' ? this.labSearch : this.semesterSearch, ...(append && cursor() ? { cursor: cursor()! } : {}) };
    const request = kind === 'laboratories' ? this.work.api.invoke(listPlanningLaboratory, query) : this.work.api.invoke(listPlanningSemester, query);
    this.work.run(request, response => {
      list.set(append ? [...list(), ...response.data.items] : response.data.items);
      cursor.set(response.data.nextCursor);
      if (!append) {
        const first = response.data.items.find(item => item.status === 'ACTIVO') ?? response.data.items[0];
        if (kind === 'laboratories') this.laboratory = first?.id ?? '';
        else this.semester = first?.id ?? '';
        this.contextChanged();
      }
    });
  }

  contextChanged(): void {
    this.newSlot(); this.schedules.set([]); this.nextCursor.set(null); this.availabilityResult.set(null);
    if (this.laboratory && this.semester) this.loadSchedules();
  }

  loadSchedules(append = false): void {
    if (!this.laboratory || !this.semester) return;
    this.work.run(this.work.api.invoke(listPlanningSchedules, { laboratory: this.laboratory, semesterId: this.semester, pageSize: 25, ...(append && this.nextCursor() ? { cursor: this.nextCursor()! } : {}) }), response => {
      this.schedules.set(append ? [...this.schedules(), ...response.data.items] : response.data.items);
      this.nextCursor.set(response.data.nextCursor);
      const current = this.selected();
      const refreshed = response.data.items.find(slot => slot.id === current?.id);
      if (current && refreshed && refreshed.version !== current.version) this.select(refreshed);
    });
  }

  select(slot: PlanningScheduleRecord): void {
    this.selected.set(slot); this.weekday = slot.weekday; this.startsAtLocal = slot.startsAtLocal; this.endsAtLocal = slot.endsAtLocal; this.active = slot.active; this.retry = null;
  }
  newSlot(): void { this.selected.set(null); this.weekday = 1; this.startsAtLocal = '07:00'; this.endsAtLocal = '08:00'; this.active = true; this.retry = null; }

  save(): void {
    if (this.work.busy() || !this.canEdit()) return;
    this.conflictNotice.set('');
    const body: PublishPlanningScheduleInput = { semesterId: this.semester, weekday: this.weekday, startsAtLocal: this.startsAtLocal, endsAtLocal: this.endsAtLocal };
    const selected = this.selected();
    const fingerprint = JSON.stringify([this.laboratory, body]);
    if (this.retry?.fingerprint !== fingerprint) this.retry = { fingerprint, key: crypto.randomUUID() };
    const key = this.retry.key;
    const request = this.work.csrf(token => selected
      ? this.work.api.invoke(updatePlanningSchedule, { laboratory: this.laboratory, schedule: selected.id, 'X-XSRF-TOKEN': token, 'If-Match': `"${selected.version}"`, body: { ...body, active: this.active } })
      : this.work.api.invoke(publishPlanningSchedule, { laboratory: this.laboratory, 'X-XSRF-TOKEN': token, 'Idempotency-Key': key, body }));
    this.work.run(request, response => { this.select(response.data); this.availabilityResult.set(null); this.loadSchedules(); }, () => {
      this.conflictNotice.set('El horario cambió o existe un solapamiento. Se recargaron los datos; revísalos antes de guardar.');
      this.loadOptions('semesters');
    });
  }

  queryAvailability(): void {
    if (this.work.busy() || !this.laboratory || !this.semesterActive()) return;
    this.conflictNotice.set('');
    const from = new Date(this.queryStart), until = new Date(this.queryEnd);
    if (!Number.isFinite(from.getTime()) || !Number.isFinite(until.getTime()) || until <= from || until.getTime() - from.getTime() > 31 * 86400000) {
      this.work.message.set('Introduce un intervalo válido de hasta 31 días.'); return;
    }
    this.availabilityResult.set(null);
    this.work.run(this.work.api.invoke(getPlanningAvailability, { laboratory: this.laboratory, startAt: from.toISOString(), endAt: until.toISOString() }), response => {
      if (response.data.semesterId !== this.semester) { this.conflictNotice.set('El semestre activo cambió. Revisa la selección y consulta de nuevo.'); this.loadOptions('semesters'); return; }
      this.availabilityResult.set(response.data);
    });
  }

  private localInput(date: Date): string { return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16); }
}
