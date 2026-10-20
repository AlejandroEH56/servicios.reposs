import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { Notices } from './notices';
import { NoticeAdmin } from './notice-admin';
import { Notice } from '../../api/models';

const fixtureNotice = (): Notice => ({ id: '01J00000000000000000000111', type: 'EMPLEO', title: 'Oportunidad institucional', description: '<img src=x onerror=alert(1)>', requirements: '', url: 'https://example.test', status: 'PUBLICADO', version: 1, resources: [], publicationWindow: { startsAt: '2026-10-19T00:00:00Z', endsAt: null }, createdAt: '2026-10-19T00:00:00Z' });
describe('Publicaciones', () => {
  let http: HttpTestingController;
  let route = { snapshot: { paramMap: convertToParamMap({}), queryParamMap: convertToParamMap({}) } };
  beforeEach(() => {
    route = { snapshot: { paramMap: convertToParamMap({}), queryParamMap: convertToParamMap({}) } };
    TestBed.configureTestingModule({ providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting(), { provide: ActivatedRoute, useValue: route }] });
    http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => { document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/'; http.verify(); });
  it('allows anonymous lists without fetching institutional identity', () => {
    const fixture = TestBed.createComponent(Notices);
    http.expectOne(request => request.url === '/api/v1/public/notices').flush({ data: [], page: { size: 10, nextCursor: null, hasMore: false }, links: { self: '', next: null } });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('No hay avisos vigentes');
    http.expectNone('/api/v1/me');
    fixture.destroy();
  });
  it('renders public text without interpreting HTML received from the API', () => {
    route.snapshot.paramMap = convertToParamMap({ id: '01J00000000000000000000111' });
    const fixture = TestBed.createComponent(Notices);
    http.expectOne('/api/v1/public/notices/01J00000000000000000000111').flush({ data: fixtureNotice() });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('<img src=x onerror=alert(1)>');
    expect(fixture.nativeElement.querySelector('img')).toBeNull();
    expect(fixture.nativeElement.querySelector('a[target="_blank"]').rel).toBe('noopener noreferrer');
    fixture.destroy();
  });
  it('shows unavailable state when an expired detail is no longer public', () => {
    route.snapshot.paramMap = convertToParamMap({ id: '01J00000000000000000000111' });
    const fixture = TestBed.createComponent(Notices);
    http.expectOne('/api/v1/public/notices/01J00000000000000000000111').flush({}, { status: 404, statusText: 'Not Found' });
    fixture.detectChanges(); expect(fixture.nativeElement.textContent).toContain('su vigencia terminó');
    expect(fixture.nativeElement.querySelector('article')).toBeNull(); fixture.destroy();
  });
  it('retains the idempotency key when retrying an unchanged draft after network failure', () => {
    const fixture = TestBed.createComponent(NoticeAdmin);
    http.expectOne(request => request.url === '/api/v1/publications/notices').flush({ data: [], page: { size: 25, nextCursor: null, hasMore: false } });
    fixture.componentInstance.form.title = 'Borrador'; fixture.componentInstance.form.description = 'Contenido';
    fixture.componentInstance.save();
    document.cookie = 'XSRF-TOKEN=unit-csrf; path=/'; http.expectOne('/sanctum/csrf-cookie').flush(null);
    const first = http.expectOne('/api/v1/publications/notices'); const key = first.request.headers.get('Idempotency-Key');
    expect(first.request.headers.get('X-XSRF-TOKEN')).toBe('unit-csrf');
    first.error(new ProgressEvent('network'));
    fixture.componentInstance.save(); http.expectOne('/sanctum/csrf-cookie').flush(null);
    const second = http.expectOne('/api/v1/publications/notices');
    expect(second.request.headers.get('Idempotency-Key')).toBe(key);
    second.error(new ProgressEvent('network')); fixture.destroy();
  });
});
