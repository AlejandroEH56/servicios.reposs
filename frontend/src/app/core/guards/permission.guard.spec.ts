import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRouteSnapshot, Router, RouterStateSnapshot, UrlTree, provideRouter } from '@angular/router';
import { Observable, firstValueFrom } from 'rxjs';
import { activeIdentity } from '../auth/identity.fixture';
import { permissionGuard } from './permission.guard';

describe('Permission guard', () => {
  let http: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()] });
    http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => http.verify());
  it.each([[[], '/access-denied'], [['organization.empleados.consultar'], true]])('uses effective local permissions %j', async (permissions, expected) => {
    const result = firstValueFrom(TestBed.runInInjectionContext(() => permissionGuard(
      { data: { permissions: ['organization.empleados.consultar'] } } as unknown as ActivatedRouteSnapshot,
      {} as RouterStateSnapshot,
    )) as Observable<true | UrlTree>);
    http.expectOne('/api/v1/me').flush({ data: { ...activeIdentity(), permissions } });
    const actual = await result;
    expect(actual === true ? true : TestBed.inject(Router).serializeUrl(actual)).toBe(expected);
  });
  it('rejects revoked sessions before loading the workspace', async () => {
    const result = firstValueFrom(TestBed.runInInjectionContext(() => permissionGuard(
      { data: { permissions: ['iam.roles.gestionar'] } } as unknown as ActivatedRouteSnapshot, {} as RouterStateSnapshot,
    )) as Observable<UrlTree>);
    http.expectOne('/api/v1/me').flush({}, { status: 401, statusText: 'Revoked' });
    expect(TestBed.inject(Router).serializeUrl(await result)).toBe('/login');
  });
});
