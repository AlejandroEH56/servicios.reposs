import { TestBed } from '@angular/core/testing';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router, provideRouter } from '@angular/router';
import { AuthFacade } from './auth.facade';
import { credentialsInterceptor } from '../http/credentials.interceptor';
import { Identity } from '../../api/models/identity';

export function activeIdentity(): Identity {
  return {
    id: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    displayName: 'Persona de ensayo',
    email: null,
    status: 'ACTIVA',
    roles: [],
    permissions: [],
    scopes: [],
    sessionExpiresAt: new Date(Date.now() + 300_000).toISOString(),
  };
}

describe('AuthFacade', () => {
  let auth: AuthFacade;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([credentialsInterceptor])),
        provideHttpClientTesting(),
      ],
    });
    auth = TestBed.inject(AuthFacade);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
    vi.useRealTimers();
  });

  it('deduplicates concurrent identity requests and accepts only an active session', () => {
    auth.refresh().subscribe();
    auth.refresh().subscribe();
    const request = http.expectOne('/api/v1/me');
    expect(request.request.withCredentials).toBe(true);
    request.flush({ data: activeIdentity() });
    expect(auth.authenticated()).toBe(true);
    expect(auth.identity()?.roles).toEqual([]);
  });

  it.each([['PENDIENTE'], ['SUSPENDIDA'], ['DESACTIVADA']])(
    'does not expose a %s identity',
    (status) => {
      auth.refresh().subscribe();
      http.expectOne('/api/v1/me').flush({ data: { ...activeIdentity(), status } });
      expect(auth.state()).toBe('denied');
      expect(auth.identity()).toBeNull();
    },
  );

  it.each([
    [401, 'anonymous'],
    [403, 'denied'],
    [503, 'error'],
  ])('handles HTTP %s without treating it as an active session', (status, state) => {
    auth.refresh().subscribe();
    http.expectOne('/api/v1/me').flush({}, { status: status as number, statusText: 'Failure' });
    expect(auth.state()).toBe(state);
    expect(auth.identity()).toBeNull();
  });

  it('removes identity and navigates to login at the server session deadline', () => {
    vi.useFakeTimers();
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    auth.refresh().subscribe();
    http.expectOne('/api/v1/me').flush({
      data: { ...activeIdentity(), sessionExpiresAt: new Date(Date.now() + 1000).toISOString() },
    });
    vi.advanceTimersByTime(1001);
    expect(auth.identity()).toBeNull();
    expect(navigate).toHaveBeenCalledWith(['/login'], { replaceUrl: true });
  });

  it.each([['not-a-date'], ['2000-01-01T00:00:00Z']])(
    'rejects invalid or expired session deadlines',
    (sessionExpiresAt) => {
      auth.refresh().subscribe();
      http.expectOne('/api/v1/me').flush({ data: { ...activeIdentity(), sessionExpiresAt } });
      expect(auth.state()).toBe('anonymous');
    },
  );

  it('bootstraps CSRF before POST logout and clears the identity after success', () => {
    auth.refresh().subscribe();
    http.expectOne('/api/v1/me').flush({ data: activeIdentity() });
    let success = false;
    document.cookie = 'XSRF-TOKEN=session-csrf; path=/';
    auth.logout().subscribe((result) => {
      success = result;
    });
    http.expectNone('/auth/entra/logout');
    http.expectOne('/sanctum/csrf-cookie').flush(null, { status: 204, statusText: 'No Content' });
    const logout = http.expectOne('/auth/entra/logout');
    expect(logout.request.method).toBe('POST');
    expect(logout.request.headers.get('X-XSRF-TOKEN')).toBe('session-csrf');
    logout.flush(null, { status: 204, statusText: 'No Content' });
    expect(success).toBe(true);
    expect(auth.identity()).toBeNull();
    expect(auth.busy()).toBe(false);
    document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/';
  });

  it('does not declare a failed logout successful or silently discard a live identity', () => {
    auth.refresh().subscribe();
    http.expectOne('/api/v1/me').flush({ data: activeIdentity() });
    let success = true;
    auth.logout().subscribe((result) => {
      success = result;
    });
    http.expectOne('/sanctum/csrf-cookie').flush({}, { status: 503, statusText: 'Unavailable' });
    http.expectNone('/auth/entra/logout');
    expect(success).toBe(false);
    expect(auth.authenticated()).toBe(true);
  });

  it('does not restore identity from an in-flight me response after logout', () => {
    auth.refresh().subscribe();
    const pending = http.expectOne('/api/v1/me');
    auth.logout().subscribe();
    http.expectOne('/sanctum/csrf-cookie').flush(null, { status: 204, statusText: 'No Content' });
    http.expectOne('/auth/entra/logout').flush(null, { status: 204, statusText: 'No Content' });
    pending.flush({ data: activeIdentity() });
    expect(auth.state()).toBe('anonymous');
    expect(auth.identity()).toBeNull();
  });
});
