import { TestBed } from '@angular/core/testing';
import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { credentialsInterceptor } from './credentials.interceptor';

describe('Session credentials', () => {
  it('includes credentials only for same-origin requests', () => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([credentialsInterceptor])),
        provideHttpClientTesting(),
      ],
    });
    const client = TestBed.inject(HttpClient);
    const http = TestBed.inject(HttpTestingController);
    client.get('/api/v1/me').subscribe();
    const local = http.expectOne('/api/v1/me');
    expect(local.request.withCredentials).toBe(true);
    local.flush({});
    client.get('https://foreign.example.test/resource', { withCredentials: true }).subscribe();
    const foreign = http.expectOne('https://foreign.example.test/resource');
    expect(foreign.request.withCredentials).toBe(false);
    expect(foreign.request.headers.has('X-XSRF-TOKEN')).toBe(false);
    foreign.flush({});
    http.verify();
  });
});
