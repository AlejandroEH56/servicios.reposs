import { HttpXsrfTokenExtractor, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { WorkspaceService } from './workspace.service';
import { grantRole } from '../../api/functions';

describe('Workspace mutations', () => {
  let work: WorkspaceService; let http: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting(),
      { provide: HttpXsrfTokenExtractor, useValue: { getToken: () => 'test-csrf' } }] });
    work = TestBed.inject(WorkspaceService); http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => http.verify());
  it('initializes CSRF and sends the concurrency version with an explicit grant', () => {
    work.run(work.csrf(token => work.api.invoke(grantRole, { identityId: 'identity', roleId: 'role', 'X-XSRF-TOKEN': token,
      body: { expectedAuthorizationVersion: 3 } })), () => {});
    expect(work.busy()).toBe(true);
    http.expectNone('/api/v1/iam/identities/identity/roles/role');
    http.expectOne('/sanctum/csrf-cookie').flush(null, { status: 204, statusText: 'No Content' });
    const request = http.expectOne('/api/v1/iam/identities/identity/roles/role');
    expect(request.request.headers.get('X-XSRF-TOKEN')).toBe('test-csrf');
    expect(request.request.body).toEqual({ expectedAuthorizationVersion: 3 });
    request.flush({ data: {} }); expect(work.busy()).toBe(false);
  });
  it('refreshes a version conflict without retrying a write', () => {
    const refresh = vi.fn();
    work.run(work.api.invoke(grantRole, { identityId: 'identity', roleId: 'role', 'X-XSRF-TOKEN': 'test', body: { expectedAuthorizationVersion: 1 } }), () => {}, refresh);
    http.expectOne('/api/v1/iam/identities/identity/roles/role').flush({}, { status: 409, statusText: 'Conflict' });
    expect(refresh).toHaveBeenCalledOnce(); expect(work.message()).toContain('conflicto');
    expect(work.busy()).toBe(false);
  });
});
