import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { AuthFacade } from '../../core/auth/auth.facade';
import { activeIdentity } from '../../core/auth/identity.fixture';
import { Organization, organizationTree } from './organization';
import { OrganizationUnit } from '../../api/models';

describe('Organization workspace', () => {
  let http: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [Organization], providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()] });
    http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => http.verify());
  it('shows self service without loading administrative or adviser directories', () => {
    TestBed.inject(AuthFacade).refresh().subscribe();
    http.expectOne('/api/v1/me').flush({ data: { ...activeIdentity(), permissions: ['organization.empleados.consultar', 'organization.grados.gestionar', 'organization.niveles.consultar'], scopes: ['organization.grados.gestionar:self'] } });
    const fixture = TestBed.createComponent(Organization);
    http.expectOne('/api/v1/organization/employees?q=').flush({ data: [] });
    http.expectOne('/api/v1/organization/levels').flush({ data: [] });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('No hay empleados visibles');
    expect(fixture.nativeElement.textContent).not.toContain('Registrar empleado');
    http.expectNone('/api/v1/organization/identity-candidates');
    http.expectNone('/api/v1/organization/units');
    http.expectNone('/api/v1/organization/advisers');
    fixture.destroy();
  });
  it('renders roots before children and terminates even for malformed cyclic data', () => {
    const unit = (id: string, parentId: string | null): OrganizationUnit => ({ id, parentId, name: id, code: id, kind: 'DEPARTAMENTO', active: true, version: 1 });
    expect(organizationTree([unit('child', 'root'), unit('root', null)]).map(row => [row.unit.id, row.depth])).toEqual([['root', 0], ['child', 1]]);
    const cycle = organizationTree([unit('a', 'b'), unit('b', 'a')]);
    expect(cycle).toHaveLength(2); expect(new Set(cycle.map(row => row.unit.id)).size).toBe(2);
  });
});
