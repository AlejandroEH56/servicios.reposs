import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { PlanningRecord, PlanningScheduleRecord } from '../../api/models';
import { AuthFacade } from '../../core/auth/auth.facade';
import { activeIdentity } from '../../core/auth/identity.fixture';
import { Calendar } from './calendar';

const id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const lab: PlanningRecord = { id, kind: 'laboratories', code: 'LAB', name: '<img src=x onerror=alert(1)>', status: 'ACTIVO', active: true, version: 1 };
const semester: PlanningRecord = { id, kind: 'semesters', name: 'Semestre de ensayo', status: 'ACTIVO', active: true, version: 2 };
const slot: PlanningScheduleRecord = { id, laboratoryId: id, semesterId: id, weekday: 1, startsAtLocal: '07:00', endsAtLocal: '08:00', active: true, version: 1, timeZone: 'America/Mexico_City' };

describe('Horarios y disponibilidad', () => {
  let http: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [Calendar], providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()] });
    http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => { document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/'; http.verify(); });

  function setup(permissions: string[], records: PlanningScheduleRecord[] = []) {
    TestBed.inject(AuthFacade).refresh().subscribe();
    http.expectOne('/api/v1/me').flush({ data: { ...activeIdentity(), permissions } });
    const fixture = TestBed.createComponent(Calendar);
    http.expectOne(request => request.url.endsWith('/laboratories')).flush({ data: { items: [lab], nextCursor: null } });
    http.expectOne(request => request.url.endsWith('/semesters')).flush({ data: { items: [semester], nextCursor: null } });
    http.expectOne(request => request.url.endsWith('/schedules')).flush({ data: { items: records, nextCursor: null } });
    fixture.detectChanges();
    return fixture;
  }

  it('permite consulta al lector sin editor ni HTML y conserva el aviso de no reserva', () => {
    const fixture = setup([]);
    expect(fixture.nativeElement.querySelector('img')).toBeNull();
    expect(fixture.nativeElement.textContent).not.toContain('Publicar franja');
    expect(fixture.nativeElement.textContent).toContain('Esta consulta no reserva el laboratorio.');
    fixture.componentInstance.queryStart = '2026-10-19T07:00';
    fixture.componentInstance.queryEnd = '2026-10-19T08:00';
    fixture.componentInstance.queryAvailability();
    const query = http.expectOne(request => request.url.endsWith('/availability'));
    expect(query.request.params.get('startAt')).toBe(new Date('2026-10-19T07:00').toISOString());
    query.flush({ data: { laboratoryId: id, semesterId: id, timeZone: 'America/Mexico_City', startAt: '2026-10-19T13:00:00Z', endAt: '2026-10-19T14:00:00Z', available: false, reservation: false, basis: 'PLANNING_CALENDAR', intervals: [], reasonCode: 'NO_SCHEDULE' } });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('No hay franjas publicadas');
    fixture.destroy();
  });

  it('reintenta la publicación con la misma clave y transmite CSRF sin permitir campos de administración', () => {
    const fixture = setup(['planning.manage']);
    document.cookie = 'XSRF-TOKEN=calendar-unit; path=/';
    fixture.componentInstance.save(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const first = http.expectOne(request => request.method === 'POST' && request.url.endsWith('/schedules'));
    const key = first.request.headers.get('Idempotency-Key');
    expect(key).toBeTruthy(); expect(first.request.headers.get('X-XSRF-TOKEN')).toBe('calendar-unit');
    expect(first.request.body).toEqual({ semesterId: id, weekday: 1, startsAtLocal: '07:00', endsAtLocal: '08:00' });
    first.error(new ProgressEvent('network'));
    fixture.componentInstance.save(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const retry = http.expectOne(request => request.method === 'POST' && request.url.endsWith('/schedules'));
    expect(retry.request.headers.get('Idempotency-Key')).toBe(key);
    retry.flush({ data: slot });
    http.expectOne(request => request.url.endsWith('/schedules')).flush({ data: { items: [{ ...slot, active: false, version: 2 }], nextCursor: null } });
    fixture.detectChanges(); expect(fixture.nativeElement.textContent).toContain('versión 2 · Retirada');
    fixture.destroy();
  });

  it('retira la franja con If-Match y refleja la versión recibida', () => {
    const fixture = setup(['planning.manage'], [slot]);
    fixture.componentInstance.select(slot); fixture.componentInstance.active = false;
    document.cookie = 'XSRF-TOKEN=calendar-unit; path=/';
    fixture.componentInstance.save(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const update = http.expectOne(request => request.method === 'PATCH');
    expect(update.request.headers.get('If-Match')).toBe('"1"');
    expect(update.request.body.active).toBe(false);
    const withdrawn = { ...slot, active: false, version: 2 };
    update.flush({ data: withdrawn });
    http.expectOne(request => request.url.endsWith('/schedules')).flush({ data: { items: [withdrawn], nextCursor: null } });
    fixture.detectChanges(); expect(fixture.nativeElement.textContent).toContain('versión 2 · Retirada');
    fixture.destroy();
  });

  it('conserva visible el conflicto y recarga el semestre antes de permitir otra edición', () => {
    const fixture = setup(['planning.manage'], [slot]);
    fixture.componentInstance.select(slot); document.cookie = 'XSRF-TOKEN=calendar-unit; path=/';
    fixture.componentInstance.save(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    http.expectOne(request => request.method === 'PATCH').flush({}, { status: 409, statusText: 'Conflict' });
    http.expectOne(request => request.url.endsWith('/semesters')).flush({ data: { items: [{ ...semester, status: 'CERRADO', active: false }], nextCursor: null } });
    http.expectOne(request => request.url.endsWith('/schedules')).flush({ data: { items: [slot], nextCursor: null } });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('El horario cambió o existe un solapamiento.');
    expect(fixture.nativeElement.textContent).not.toContain('Publicar franja');
    fixture.destroy();
  });

  it('descarta la consulta si el semestre activo cambió y mantiene visible la advertencia tras recargar', () => {
    const fixture = setup([]);
    fixture.componentInstance.queryStart = '2026-10-19T07:00'; fixture.componentInstance.queryEnd = '2026-10-19T08:00';
    fixture.componentInstance.queryAvailability();
    const other = '01ARZ3NDEKTSV4RRFFQ69G5FAW';
    http.expectOne(request => request.url.endsWith('/availability')).flush({ data: { laboratoryId: id, semesterId: other, timeZone: 'America/Mexico_City', startAt: '2026-10-19T13:00:00Z', endAt: '2026-10-19T14:00:00Z', available: false, reservation: false, basis: 'PLANNING_CALENDAR', intervals: [], reasonCode: 'NO_SCHEDULE' } });
    http.expectOne(request => request.url.endsWith('/semesters')).flush({ data: { items: [{ ...semester, id: other }], nextCursor: null } });
    http.expectOne(request => request.url.endsWith('/schedules')).flush({ data: { items: [], nextCursor: null } });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('El semestre activo cambió.');
    expect(fixture.componentInstance.semester).toBe(other);
    fixture.destroy();
  });
});
