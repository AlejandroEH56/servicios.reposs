import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { AuthFacade } from '../../core/auth/auth.facade';
import { activeIdentity } from '../../core/auth/identity.fixture';
import { Planning } from './planning';
import { PlanningKind } from './planning-client';

interface Editor { changeKind(kind: PlanningKind): void; draft: Record<string, string | boolean | number | null>; save(): void; }

describe('Planificación académica', () => {
  let http: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [Planning], providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()] });
    http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => { document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/'; http.verify(); });

  function identity(permissions: string[]): void {
    TestBed.inject(AuthFacade).refresh().subscribe();
    http.expectOne('/api/v1/me').flush({ data: { ...activeIdentity(), permissions } });
  }

  it('permite consulta sin mostrar editor ni cargar directorio de responsables', () => {
    identity([]);
    const fixture = TestBed.createComponent(Planning);
    http.expectOne(request => request.url.endsWith('/catalogs/careers')).flush({ data: { items: [{ id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', kind: 'careers', name: '<img src=x onerror=alert(1)>', active: true, version: 1 }], nextCursor: null } });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('<img src=x onerror=alert(1)>');
    expect(fixture.nativeElement.querySelector('img')).toBeNull();
    expect(fixture.nativeElement.textContent).not.toContain('Nuevo registro');
    http.expectNone('/api/v1/academic-planning/responsibles');
    fixture.destroy();
  });

  it('conserva la clave idempotente al reintentar un semestre tras fallo de red', () => {
    identity(['planning.manage', 'planning.catalogs.manage']);
    const fixture = TestBed.createComponent(Planning);
    const editor = fixture.componentInstance as unknown as Editor;
    http.expectOne(request => request.url.endsWith('/catalogs/careers')).flush({ data: { items: [], nextCursor: null } });
    editor.changeKind('semesters');
    http.expectOne(request => request.url.endsWith('/semesters')).flush({ data: { items: [], nextCursor: null } });
    editor.draft = { code: '2026-2', name: 'Agosto a diciembre', startsOn: '2026-08-01', endsOn: '2026-12-18' };
    document.cookie = 'XSRF-TOKEN=unit-planning; path=/';
    editor.save(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const first = http.expectOne('/api/v1/academic-planning/semesters');
    const key = first.request.headers.get('Idempotency-Key');
    expect(key).toBeTruthy();
    expect(first.request.headers.get('X-XSRF-TOKEN')).toBe('unit-planning');
    expect(first.request.body.status).toBeUndefined();
    expect(first.request.body.startsOn).toBe('2026-08-01');
    first.error(new ProgressEvent('network'));
    editor.save(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const retry = http.expectOne('/api/v1/academic-planning/semesters');
    expect(retry.request.headers.get('Idempotency-Key')).toBe(key);
    retry.error(new ProgressEvent('network'));
    fixture.destroy();
  });

  it('carga referencias por el puerto de planificación sin acceder a datos completos de empleados', () => {
    identity(['planning.manage']);
    const fixture = TestBed.createComponent(Planning);
    http.expectOne(request => request.url.endsWith('/catalogs/careers')).flush({ data: { items: [], nextCursor: null } });
    (fixture.componentInstance as unknown as Editor).changeKind('laboratories');
    http.expectOne(request => request.url.endsWith('/catalogs/careers')).flush({ data: { items: [], nextCursor: null } });
    http.expectOne('/api/v1/academic-planning/responsibles').flush({ data: [] });
    http.expectOne(request => request.url.endsWith('/laboratories')).flush({ data: { items: [], nextCursor: null } });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('Empleado responsable');
    http.expectNone(request => request.url.includes('/organization/employees'));
    fixture.destroy();
  });
});
