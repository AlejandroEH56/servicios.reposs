import { test, expect } from '@playwright/test';

const id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const notice = { id, type: 'EMPLEO', title: 'Convocatoria de ensayo', description: 'Texto público seguro', requirements: 'Requisito', url: 'https://example.test/aviso', publicationWindow: { startsAt: '2026-10-01T00:00:00Z', endsAt: null }, status: 'BORRADOR', version: 1, resources: [], createdAt: '2026-10-01T00:00:00Z' };
async function identity(page, permissions) {
  await page.route('**/api/v1/me', route => route.fulfill({ json: { data: { id, displayName: 'Ensayo de publicaciones', email: null, status: 'ACTIVA', roles: [], permissions, scopes: [], sessionExpiresAt: new Date(Date.now()+300000).toISOString() } } }));
}

test('portal público real carga bajo CSP con nonce y Trusted Types en enforcement', async ({ page }) => {
  const violations = [];
  await page.addInitScript(() => { globalThis.publicationViolations = []; document.addEventListener('securitypolicyviolation', event => globalThis.publicationViolations.push(event.effectiveDirective)); });
  page.on('pageerror', error => violations.push(error.message));
  const response = await page.goto('/portal/notices');
  await expect(page.getByRole('heading', { name: 'Convocatorias y oportunidades' })).toBeVisible();
  await expect(page.getByRole('status')).toHaveCount(0);
  await expect(page.getByRole('alert')).toHaveCount(0);
  const policy = response.headers()['content-security-policy'];
  expect(policy).toContain("require-trusted-types-for 'script'");
  expect(policy).toContain("trusted-types angular angular#bundler");
  expect(policy).not.toContain('unsafe-inline');
  expect(violations).toEqual([]);
  expect(await page.evaluate(() => globalThis.publicationViolations)).toEqual([]);
  const blocked = await page.evaluate(() => { try { document.createElement('div').innerHTML = '<img src=x onerror=alert(1)>'; return false; } catch (error) { return error instanceof TypeError; } });
  expect(blocked).toBe(true);
});

test('ninguna entrada HTML del portal omite nonce, CSP o no-store', async ({ request }) => {
  for (const path of ['/portal/notices', '/portal/index.html', '/portal/publications']) {
    const result = await request.get(path);
    expect(result.status()).toBe(200);
    expect(result.headers()['content-security-policy']).toContain("'nonce-");
    expect(result.headers()['cache-control']).toContain('no-store');
    expect(await result.text()).toContain('ngCspNonce=');
  }
});

test('perfil sin permiso no entra al editor de publicaciones', async ({ page }) => {
  await identity(page, []);
  await page.goto('/portal/publications');
  await expect(page).toHaveURL(/access-denied$/);
});

test('editor crea, publica y retira con CSRF, versión y clave idempotente', async ({ page }) => {
  await identity(page, ['publicaciones.administrar']);
  let current = null;
  await page.route('**/api/v1/publications/notices**', async route => {
    const request = route.request();
    if (request.method() === 'GET') return route.fulfill({ json: { data: current ? [current] : [], page: { size: 25, nextCursor: null, hasMore: false }, links: { self: '', next: null } } });
    expect(request.headers()['x-xsrf-token']).toBeTruthy();
    expect(request.headers()['idempotency-key']).toBeTruthy();
    const path = new URL(request.url()).pathname;
    if (path.endsWith('/publish')) { expect(request.headers()['if-match']).toBe('"1"'); current = { ...current, status: 'PUBLICADO', version: 2 }; }
    else if (path.endsWith('/withdraw')) { expect(request.headers()['if-match']).toBe('"2"'); current = { ...current, status: 'RETIRADO', version: 3 }; }
    else { expect(request.postDataJSON().title).toBe('Convocatoria de ensayo'); current = { ...notice, ...request.postDataJSON() }; }
    return route.fulfill({ status: path.endsWith('/notices') ? 201 : 200, json: { data: current } });
  });
  await page.goto('/portal/publications');
  await page.getByLabel('Título', { exact: true }).fill('Convocatoria de ensayo');
  await page.getByLabel('Descripción', { exact: true }).fill('Texto público seguro');
  await page.getByLabel('Inicio de publicación (hora local)').fill('2026-10-01T09:00');
  await page.getByRole('button', { name: 'Guardar borrador' }).click();
  await expect(page.getByRole('button', { name: 'Guardar cambios' })).toBeVisible();
  await page.getByRole('button', { name: 'Publicar aviso', exact: true }).click();
  await expect(page.getByText('Estado: PUBLICADO', { exact: false })).toBeVisible();
  await page.getByRole('button', { name: 'Retirar aviso', exact: true }).click();
  await expect(page.getByText('Estado: RETIRADO', { exact: false })).toBeVisible();
});

test('API real mantiene negativos de autorización y CSRF', async ({ request }) => {
  expect((await request.get('/api/v1/public/notices')).status()).toBe(200);
  expect((await request.get('/api/v1/publications/notices')).status()).toBe(401);
  expect((await request.post('/api/v1/publications/notices', { data: { type: 'EMPLEO', title: 'Prohibido', description: 'Prohibido' } })).status()).toBe(419);
  expect((await request.post('/api/v1/files/staged', { multipart: { purpose: 'NOTICE_IMAGE' } })).status()).toBe(419);
});
