import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { ResidencyRecord } from '../../api/models';
import { AuthFacade } from '../../core/auth/auth.facade';
import { activeIdentity } from '../../core/auth/identity.fixture';
import { ResidencyBody, ResidencyKind } from './residency-client';
import { Residencies } from './residencies';

const id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const companyId = '01ARZ3NDEKTSV4RRFFQ69G5FAW';
const periodId = '01ARZ3NDEKTSV4RRFFQ69G5FAX';
const projectId = '01ARZ3NDEKTSV4RRFFQ69G5FAY';
const assignmentId = '01ARZ3NDEKTSV4RRFFQ69G5FAZ';
const personal = ['residencies.files.read', 'residencies.profile.manage', 'residencies.projects.manage', 'residencies.advisers.assign', 'residencies.companies.create', 'residencies.companies.manage'];
interface Harness {
  changeKind(kind: ResidencyKind): void;
  open(record: ResidencyRecord): void;
  save(): void;
  assign(): void;
  endAssignment(type: 'internal' | 'external', record: ResidencyRecord): void;
  draft: ResidencyBody;
  selected: Signal<ResidencyRecord | null>;
  adviserReference: string;
  adviserStart: string;
  assignmentEnds: Record<string, string>;
}

describe('Residencias profesionales', () => {
  let http: HttpTestingController;
  let reads: string[];
  let own: ResidencyRecord | null;
  let company: ResidencyRecord;
  let period: ResidencyRecord;
  let project: ResidencyRecord;
  const resident: ResidencyRecord = { id, version: 1, kind: 'residents', name: 'Persona Piloto', givenNames: 'Persona', firstSurname: 'Piloto', programId: id, controlNumber: '21000001', status: 'ACTIVO' };
  const program: ResidencyRecord = { id, version: 1, kind: 'programs', name: 'Programa de ensayo', active: true, modalityId: id };

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [Residencies], providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()] });
    http = TestBed.inject(HttpTestingController); reads = []; own = null;
    company = { id: companyId, version: 1, kind: 'companies', name: '<img src=x onerror=alert(1)>', rfc: 'XAXX010101000', status: 'ACTIVO', canManage: false, canReadContacts: false };
    period = { id: periodId, version: 1, kind: 'periods', name: 'Periodo de ensayo', startsOn: '2026-08-01', endsOn: '2026-12-31', durationMonths: 5, status: 'ACTIVO' };
    project = { id: projectId, version: 1, kind: 'projects', residentId: id, periodId, programId: id, companyId, name: 'Proyecto de ensayo', startsOn: '2026-08-01', endsOn: '2026-12-31', vacancies: 1, status: 'BORRADOR', internalAdvisers: [], externalAdvisers: [] };
  });
  afterEach(() => { document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/'; http.verify(); });
  const harness = (fixture: ComponentFixture<Residencies>) => fixture.componentInstance as unknown as Harness;
  const field = (fixture: ComponentFixture<Residencies>, label: string): HTMLInputElement | null => Array.from(fixture.nativeElement.querySelectorAll('label') as NodeListOf<HTMLLabelElement>).find(element => element.textContent?.trim() === label)?.querySelector('input') ?? null;

  function drain(fixture: ComponentFixture<Residencies>) {
    for (let i = 0; i < 20; i++) {
      const requests = http.match(request => request.method === 'GET' && request.url.startsWith('/api/v1/residencies/'));
      if (!requests.length) break;
      for (const pending of requests) {
        const path = pending.request.url.replace('/api/v1/residencies/', ''); reads.push(path);
        if (path === 'me') { pending.flush({ data: { resident: own, projects: { items: own ? [project] : [], nextCursor: null } } }); continue; }
        if (path === 'identity-candidates') { pending.flush({ data: { items: [{ id, displayName: 'Identidad de ensayo', status: 'ACTIVA' }], nextCursor: null } }); continue; }
        if (path === 'adviser-candidates') { pending.flush({ data: { items: [{ id, displayName: 'Asesor de ensayo', positions: [], degrees: [] }], nextCursor: null } }); continue; }
        if (path.endsWith('/contacts')) { pending.flush({ data: { items: [], nextCursor: null } }); continue; }
        const segments = path.replace('catalogs/', '').split('/');
        const rows: Record<string, ResidencyRecord[]> = { residents: [resident], companies: [company], periods: [period], programs: [program], projects: [project], modalities: [{ id, version: 1, kind: 'modalities', name: 'Modalidad', active: true }], sectors: [], 'business-lines': [] };
        const items = rows[segments[0]] ?? [];
        pending.flush({ data: segments.length > 1 ? items.find(row => row.id === segments[1]) : { items, nextCursor: null } });
      }
    }
    fixture.detectChanges();
  }

  function setup(permissions: string[], self = false, global = false) {
    if (self) own = resident;
    TestBed.inject(AuthFacade).refresh().subscribe();
    http.expectOne('/api/v1/me').flush({ data: { ...activeIdentity(), permissions, scopes: permissions.map(permission => permission + (global ? ':global' : ':self')) } });
    const fixture = TestBed.createComponent(Residencies); fixture.detectChanges(); drain(fixture); return fixture;
  }

  it('explica inscripción explícita al lector y no carga identidades ni contactos privados', () => {
    const fixture = setup([]);
    expect(fixture.nativeElement.textContent).toContain('Tu identidad aún no está inscrita');
    expect(fixture.nativeElement.textContent).not.toContain('Identidad institucional');
    harness(fixture).changeKind('companies'); drain(fixture);
    harness(fixture).open(company); drain(fixture);
    expect(fixture.nativeElement.querySelector('img')).toBeNull();
    expect(fixture.nativeElement.textContent).toContain('<img src=x onerror=alert(1)>');
    expect(reads).not.toContain('identity-candidates'); expect(reads.some(path => path.endsWith('/contacts'))).toBe(false);
    fixture.destroy();
  });

  it('inscribe la identidad administrativa con CSRF y conserva el formulario al rechazar campos', () => {
    const fixture = setup([...personal, 'residencies.residents.enroll'], false, true);
    const component = harness(fixture);
    component.draft = { identityId: id, controlNumber: '99009901', programId: id, givenNames: 'Persona', firstSurname: 'Piloto', curp: 'invalida' };
    document.cookie = 'XSRF-TOKEN=residencies-unit; path=/';
    component.save(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    http.expectOne(request => request.method === 'POST').flush({ invalidFields: ['curp', '<script>'] }, { status: 422, statusText: 'Unprocessable Content' });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('Revisa CURP (opcional, 18 letras mayúsculas o dígitos).');
    expect(fixture.nativeElement.textContent).not.toContain('<script>');
    expect(component.draft['controlNumber']).toBe('99009901');
    component.draft['curp'] = '';
    component.save(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const enrollment = http.expectOne(request => request.method === 'POST');
    expect(enrollment.request.url).toBe('/api/v1/residencies/residents');
    expect(enrollment.request.body).toEqual({ identityId: id, controlNumber: '99009901', programId: id, givenNames: 'Persona', firstSurname: 'Piloto', secondSurname: null, address: null, city: null, socialSecurityNumber: null, insuranceType:null, phone: null, mobilePhone: null, curp: null });
    expect(enrollment.request.headers.get('X-XSRF-TOKEN')).toBe('residencies-unit');
    enrollment.flush({ data: resident }); drain(fixture);
    expect(component.selected()?.id).toBe(id); fixture.destroy();
  });

  it('edita sólo el perfil vinculado y mantiene identidad y número de control fuera del formulario', () => {
    const fixture = setup(personal, true);
    expect(fixture.nativeElement.textContent).toContain('21000001');
    expect(field(fixture, 'Nombres')).not.toBeNull();
    expect(field(fixture, 'Identidad institucional')).toBeNull();
    expect(field(fixture, 'Número de control (8 o 9 dígitos)')).toBeNull();
    expect(reads).not.toContain('residents'); expect(reads).not.toContain('identity-candidates'); fixture.destroy();
  });

  it('reintenta alta de empresa con misma clave y CSRF y toma la versión de lectura posterior', async () => {
    const fixture = setup(personal); const component = harness(fixture);
    component.changeKind('companies'); drain(fixture);
    component.draft = { name: 'Empresa de ensayo', rfc: 'XAXX010101000', status: 'ACTIVO', ownerId: 'No permitido' };
    document.cookie = 'XSRF-TOKEN=residencies-unit; path=/';
    component.save(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const first = http.expectOne(request => request.method === 'POST'); const key = first.request.headers.get('Idempotency-Key');
    expect(key).toBeTruthy(); expect(first.request.headers.get('X-XSRF-TOKEN')).toBe('residencies-unit'); expect(first.request.body.ownerId).toBeUndefined();
    first.error(new ProgressEvent('network'));
    component.save(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const retry = http.expectOne(request => request.method === 'POST'); expect(retry.request.headers.get('Idempotency-Key')).toBe(key);
    const original = { ...company, canManage: true, canReadContacts: true };
    company = { ...original, version: 2, name: 'Dato actualizado del servidor' };
    retry.flush({ data: original }); drain(fixture);
    await fixture.whenStable(); fixture.detectChanges();
    expect(component.selected()?.version).toBe(2); expect(component.draft['name']).toBe('Dato actualizado del servidor');
    expect(field(fixture, 'RFC (único e inmutable)')?.disabled).toBe(true); fixture.destroy();
  });

  it('asigna y finaliza asesor con versión del proyecto y conserva los snapshots de lectura', () => {
    const fixture = setup(personal, true); const component = harness(fixture);
    component.changeKind('projects'); drain(fixture); component.open(project); drain(fixture);
    component.adviserReference = id; component.adviserStart = '2026-08-01';
    document.cookie = 'XSRF-TOKEN=residencies-unit; path=/';
    component.assign(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const assign = http.expectOne(request => request.method === 'POST');
    expect(assign.request.headers.get('If-Match')).toBe('"1"'); expect(assign.request.headers.get('X-XSRF-TOKEN')).toBe('residencies-unit');
    expect(assign.request.body).toEqual({ employeeId: id, roleCode: 'PRINCIPAL', startsOn: '2026-08-01', endsOn: null });
    const assignment: ResidencyRecord = { id: assignmentId, kind: 'internal-advisers', version: 1, projectId, employeeId: id, nameSnapshot: 'Nombre histórico', roleCode: 'PRINCIPAL', startsOn: '2026-08-01', endsOn: null };
    project = { ...project, version: 2, internalAdvisers: [assignment] }; assign.flush({ data: { ...assignment, projectVersion: 2 } }); drain(fixture);
    expect(fixture.nativeElement.textContent).toContain('Nombre histórico');
    component.assignmentEnds[assignmentId] = '2026-09-30'; component.endAssignment('internal', assignment); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const end = http.expectOne(request => request.method === 'PATCH'); expect(end.request.headers.get('If-Match')).toBe('"2"'); expect(end.request.body).toEqual({ endsOn: '2026-09-30' });
    project = { ...project, version: 3, internalAdvisers: [{ ...assignment, endsOn: '2026-09-30', version: 2 }] };
    end.flush({ data: { ...assignment, endsOn: '2026-09-30', projectVersion: 3 } }); drain(fixture);
    expect(component.selected()?.version).toBe(3); expect(fixture.nativeElement.textContent).toContain('2026-09-30'); fixture.destroy();
  });

  it('mantiene conflicto visible y retira edición y asesoría al detectar periodo cerrado', () => {
    const fixture = setup(personal, true); const component = harness(fixture);
    component.changeKind('projects'); drain(fixture); component.open(project); drain(fixture);
    document.cookie = 'XSRF-TOKEN=residencies-unit; path=/'; component.save(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    project = { ...project, version: 2 }; period = { ...period, status: 'CERRADO', version: 2 };
    http.expectOne(request => request.method === 'PATCH').flush({}, { status: 409, statusText: 'Conflict' }); drain(fixture);
    expect(fixture.nativeElement.textContent).toContain('Hay un conflicto. Se recargaron los datos');
    expect(fixture.nativeElement.textContent).not.toContain('Asignar asesor al proyecto');
    expect(fixture.nativeElement.textContent).not.toContain('Guardar cambios'); fixture.destroy();
  });
});
