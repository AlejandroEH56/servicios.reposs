import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import {
  ActivatedRouteSnapshot,
  Router,
  RouterStateSnapshot,
  UrlTree,
  provideRouter,
} from '@angular/router';
import { Observable, firstValueFrom } from 'rxjs';
import { authGuard, anonymousGuard } from './auth.guard';

describe('Session guards', () => {
  let http: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => http.verify());

  it.each([
    [401, '/login'],
    [403, '/access-denied'],
    [503, '/session-error'],
  ])('redirects HTTP %s to %s', async (status, path) => {
    const result = firstValueFrom(
      TestBed.runInInjectionContext(() =>
        authGuard({} as ActivatedRouteSnapshot, {} as RouterStateSnapshot),
      ) as Observable<UrlTree>,
    );
    http.expectOne('/api/v1/me').flush({}, { status: status as number, statusText: 'Failure' });
    expect(TestBed.inject(Router).serializeUrl(await result)).toBe(path);
  });

  it('does not automatically retry institutional login for an anonymous visitor', async () => {
    const result = firstValueFrom(
      TestBed.runInInjectionContext(() =>
        anonymousGuard({} as ActivatedRouteSnapshot, {} as RouterStateSnapshot),
      ) as Observable<boolean>,
    );
    http.expectOne('/api/v1/me').flush({}, { status: 401, statusText: 'Anonymous' });
    expect(await result).toBe(true);
  });
});
