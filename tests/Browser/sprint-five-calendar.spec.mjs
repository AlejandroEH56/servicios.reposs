import { test, expect } from '@playwright/test';

const id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
async function mockCalendar(page, permissions) {
  let slot = null;
  const calls = { publish: 0, update: 0 };
  await page.route('**/api/v1/me', route => route.fulfill({ json: { data: { id, displayName: 'Ensayo de calendario', email: null, status: 'ACTIVA', roles: [], permissions, scopes: [], sessionExpiresAt: new Date(Date.now() + 300000).toISOString() } } }));
  await page.route('**/api/v1/academic-planning/**', async route => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname;
    if (path.endsWith('/laboratories')) return route.fulfill({ json: { data: { items: [{ id, kind: 'laboratories', code: 'LAB', name: '<img src=x onerror=alert(1)>', status: 'ACTIVO', active: true, version: 1 }], nextCursor: null } } });
    if (path.endsWith('/semesters')) return route.fulfill({ json: { data: { items: [{ id, kind: 'semesters', name: 'Semestre de ensayo', startsOn: '2026-10-01', endsOn: '2026-11-30', status: 'ACTIVO', active: true, version: 2 }], nextCursor: null } } });
    if (path.endsWith('/availability')) return route.fulfill({ json: { data: { laboratoryId: id, semesterId: id, timeZone: 'America/Mexico_City', startAt: url.searchParams.get('startAt'), endAt: url.searchParams.get('endAt'), available: Boolean(slot?.active), reservation: false, basis: 'PLANNING_CALENDAR', intervals: slot?.active ? [{ startAt: url.searchParams.get('startAt'), endAt: url.searchParams.get('endAt') }] : [], reasonCode: slot?.active ? null : 'NO_SCHEDULE' } } });
    if (request.method() === 'GET') return route.fulfill({ json: { data: { items: slot ? [slot] : [], nextCursor: null } } });
    expect(request.headers()['x-xsrf-token']).toBeTruthy();
    if (request.method() === 'POST') {
      calls.publish++; expect(request.headers()['idempotency-key']).toBeTruthy();
      expect(request.postDataJSON().active).toBeUndefined();
      slot = { ...request.postDataJSON(), id, laboratoryId: id, timeZone: 'America/Mexico_City', active: true, version: 1 };
    } else {
      calls.update++; expect(request.headers()['if-match']).toBe('"1"');
      expect(request.postDataJSON().active).toBe(false);
      slot = { ...slot, ...request.postDataJSON(), version: 2 };
    }
    return route.fulfill({ status: request.method() === 'POST' ? 201 : 200, json: { data: slot } });
  });
  return calls;
}

test('lector consulta calendario sin editor ni HTML y ve que no es una reserva', async ({ page }) => {
  await mockCalendar(page, []);
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  const response = await page.goto('/portal/academic-planning/calendar');
  await expect(page.getByRole('heading', { name: 'Horarios y disponibilidad' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Consultar disponibilidad' })).toBeEnabled();
  await expect(page.getByRole('button', { name: 'Publicar franja', exact: true })).toHaveCount(0);
  await expect(page.locator('img')).toHaveCount(0);
  await expect(page.getByText('Esta consulta no reserva el laboratorio.', { exact: false })).toBeVisible();
  await page.getByLabel('Inicio (hora del equipo)', { exact: true }).fill('2026-10-19T07:00');
  await page.getByLabel('Fin exclusivo (hora del equipo)', { exact: true }).fill('2026-10-19T08:00');
  await page.getByRole('button', { name: 'Consultar disponibilidad' }).click();
  await expect(page.getByText('No hay intervalos disponibles.', { exact: true })).toBeVisible();
  expect(response.headers()['content-security-policy']).toContain("require-trusted-types-for 'script'");
  expect(errors).toEqual([]);
});

test('administrador publica y retira con CSRF e If-Match y vuelve a consultar', async ({ page }) => {
  const calls = await mockCalendar(page, ['planning.manage']);
  await page.goto('/portal/academic-planning/calendar');
  await page.getByRole('button', { name: 'Publicar franja', exact: true }).click();
  await expect(page.getByText('Franja seleccionada · versión 1 · Publicada', { exact: true })).toBeVisible();
  await page.getByLabel('Franja publicada', { exact: true }).uncheck();
  await page.getByRole('button', { name: 'Guardar franja', exact: true }).click();
  await expect(page.getByText('Franja seleccionada · versión 2 · Retirada', { exact: true })).toBeVisible();
  await page.getByLabel('Inicio (hora del equipo)', { exact: true }).fill('2026-10-19T07:00');
  await page.getByLabel('Fin exclusivo (hora del equipo)', { exact: true }).fill('2026-10-19T08:00');
  await page.getByRole('button', { name: 'Consultar disponibilidad' }).click();
  await expect(page.getByText('No hay intervalos disponibles.', { exact: true })).toBeVisible();
  expect(calls).toEqual({ publish: 1, update: 1 });
});

test('API real del calendario rechaza lectura anónima y escritura sin CSRF', async ({ request }) => {
  const root = '/api/v1/academic-planning/laboratories/' + id;
  expect((await request.get(root + '/schedules?semesterId=' + id)).status()).toBe(401);
  expect((await request.get(root + '/availability?startAt=2026-10-19T13%3A00%3A00Z&endAt=2026-10-19T14%3A00%3A00Z')).status()).toBe(401);
  expect((await request.post(root + '/schedules', { data: {} })).status()).toBe(419);
  expect((await request.patch(root + '/schedules/' + id, { data: {} })).status()).toBe(419);
});
