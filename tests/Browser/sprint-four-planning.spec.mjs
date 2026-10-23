import { test, expect } from '@playwright/test';

const id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
async function identity(page, permissions) {
  await page.route('**/api/v1/me', route => route.fulfill({ json: { data: { id, displayName: 'Ensayo de planificación', email: null, status: 'ACTIVA', roles: [], permissions, scopes: [], sessionExpiresAt: new Date(Date.now() + 300000).toISOString() } } }));
}

test('API real de planificación rechaza lectura anónima y escritura sin CSRF', async ({ request }) => {
  for (const path of ['catalogs/careers', 'semesters', 'laboratories', 'holidays', 'responsibles']) {
    expect((await request.get('/api/v1/academic-planning/' + path)).status()).toBe(401);
  }
  expect((await request.post('/api/v1/academic-planning/semesters', { data: {} })).status()).toBe(419);
});

test('lector institucional consulta sin editor y el portal mantiene CSP', async ({ page }) => {
  await identity(page, []);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/api/v1/academic-planning/**', route => route.fulfill({ json: { data: { items: [{ id, kind: 'careers', code: 'ISC', name: '<img src=x onerror=alert(1)>', version: 1, active: true }], nextCursor: null } } }));
  const response = await page.goto('/portal/academic-planning');
  await expect(page.getByRole('heading', { name: 'Planificación académica' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'ISC · <img src=x onerror=alert(1)>' })).toBeVisible();
  await expect(page.locator('img')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Nuevo registro' })).toHaveCount(0);
  expect(response.headers()['content-security-policy']).toContain("require-trusted-types-for 'script'");
  expect(errors).toEqual([]);
});

test('editor de semestre envía CSRF, idempotencia y versiones de transición', async ({ page }) => {
  await identity(page, ['planning.manage', 'planning.catalogs.manage']);
  let current = null;
  await page.route('**/api/v1/academic-planning/**', async route => {
    const request = route.request();
    if (request.method() === 'GET') return route.fulfill({ json: { data: { items: current ? [current] : [], nextCursor: null } } });
    expect(request.headers()['x-xsrf-token']).toBeTruthy();
    expect(request.headers()['idempotency-key']).toBeTruthy();
    const path = new URL(request.url()).pathname;
    if (path.endsWith('/activate')) {
      expect(request.headers()['if-match']).toBe('"1"'); current = { ...current, status: 'ACTIVO', active: true, version: 2 };
    } else if (path.endsWith('/close')) {
      expect(request.headers()['if-match']).toBe('"2"'); current = { ...current, status: 'CERRADO', active: false, version: 3 };
    } else {
      expect(request.postDataJSON().status).toBeUndefined();
      current = { ...request.postDataJSON(), id, kind: 'semesters', status: 'PLANIFICADO', active: false, version: 1 };
    }
    return route.fulfill({ status: path.endsWith('/semesters') ? 201 : 200, json: { data: current } });
  });
  await page.goto('/portal/academic-planning');
  await page.getByLabel('Catálogo', { exact: true }).selectOption('semesters');
  await page.getByLabel('Código', { exact: true }).fill('2026-2');
  await page.getByLabel('Nombre', { exact: true }).fill('Agosto a diciembre');
  await page.getByLabel('Fecha de inicio', { exact: true }).fill('2026-08-01');
  await page.getByLabel('Fecha de fin', { exact: true }).fill('2026-12-18');
  await page.getByRole('button', { name: 'Registrar', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Activar semestre' })).toBeVisible();
  await page.getByRole('button', { name: 'Activar semestre' }).click();
  await expect(page.getByRole('button', { name: 'Cerrar semestre' })).toBeVisible();
  await page.getByRole('button', { name: 'Cerrar semestre' }).click();
  await expect(page.getByText('Seleccionado: Agosto a diciembre · versión 3 · CERRADO')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Guardar cambios' })).toBeDisabled();
});
