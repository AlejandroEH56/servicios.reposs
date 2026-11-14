import { test, expect } from '@playwright/test';

const id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const root = '/api/v1/laboratory-requests';

async function mockCapture(page, permissions) {
  let row = null;
  const calls = [];
  await page.route('**/api/v1/me', route => route.fulfill({json: {data: {
    id, displayName: 'Solicitante de ensayo', status: 'ACTIVA', email: null, roles: [], permissions,
    scopes: [], sessionExpiresAt: new Date(Date.now() + 300000).toISOString(),
  }}}));
  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({
    status: 204, headers: {'Set-Cookie': 'XSRF-TOKEN=fixture-sprint9; Path=/; SameSite=Lax'},
  }));
  await page.route('**/api/v1/academic-planning/**', route => route.fulfill({json: {data: {
    items: route.request().url().includes('/laboratories')
      ? [{id, name: 'Laboratorio piloto', active: true, capacity: 25}]
      : [], nextCursor: null,
  }}}));
  await page.route(/\/api\/v1\/laboratory-requests(?:[/?]|$)/, async route => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    const respond = (data, status = 200) => route.fulfill({status, json: {data}});
    if (request.method() === 'GET') return respond(path === root ? {items: row ? [row] : [], nextCursor: null} : row);
    expect(request.headers()['x-xsrf-token']).toBeTruthy();
    if (path.endsWith('/availability-quotes')) {
      const body = request.postDataJSON();
      expect(body.startAt).toBe('2027-03-01T07:00:00-06:00');
      calls.push('quote');
      return respond({...body, semesterId: id, timeZone: 'America/Mexico_City', available: true, reservation: false,
        basis: 'PLANNING_CALENDAR', intervals: [{startAt: body.startAt, endAt: body.endAt}], reasonCode: null});
    }
    if (path === root) {
      const input = request.postDataJSON();
      expect(input.type).toBe('VARIA');
      expect(input.various).toEqual({useCode: 'PROYECTO', description: 'Actividad del equipo'});
      expect(input.practice).toBeUndefined();
      expect(input.extraordinary).toBeUndefined();
      expect(request.headers()['idempotency-key']).toBeTruthy();
      calls.push('create');
      row = {...input, id, semesterId: id, status: 'BORRADOR', version: 1, submittedAt: null};
      return respond(row, 201);
    }
    if (path.endsWith('/submit')) {
      expect(request.headers()['if-match']).toBe('"2"');
      expect(request.headers()['idempotency-key']).toBeTruthy();
      calls.push('submit');
      row = {...row, status: 'PENDIENTE', version: 3, submittedAt: '2027-02-28T13:00:00Z'};
      return respond(row);
    }
    expect(request.method()).toBe('PATCH');
    expect(request.headers()['if-match']).toBe('"1"');
    calls.push('update');
    row = {...row, ...request.postDataJSON(), version: 2};
    return respond(row);
  });
  return calls;
}

async function chooseTime(page) {
  await page.locator('select[name=laboratory]').selectOption(id);
  await page.getByLabel('Fecha', {exact: true}).fill('2027-03-01');
  await page.getByRole('button', {name: 'Cotizar disponibilidad', exact: true}).click();
  await expect(page.getByText('El intervalo está disponible según el calendario.')).toBeVisible();
}

test('solicitante cotiza, guarda sólo el subtipo activo y envía el último borrador guardado', async ({page}) => {
  const calls = await mockCapture(page, ['laboratorios.solicitudes.crear']);
  await page.goto('/portal/laboratory-requests');
  await chooseTime(page);
  await page.getByRole('button', {name: 'Continuar a los detalles'}).click();
  await page.getByRole('combobox', {name: 'Tipo', exact: true}).selectOption('VARIA');
  await expect(page.getByLabel('Nombre de la práctica')).toHaveCount(0);
  await page.getByLabel('Título').fill('Proyecto piloto');
  await page.getByLabel('Propósito').fill('Prueba funcional');
  await page.getByLabel('Descripción de las tareas').fill('Actividad del equipo');
  await page.getByRole('button', {name: 'Guardar borrador'}).click();
  const submit = page.getByRole('button', {name: 'Enviar a revisión'});
  await expect(submit).toBeEnabled();
  await page.getByRole('button', {name: 'Cambiar horario'}).click();
  await page.getByLabel('Fin', {exact: true}).fill('08:30');
  await page.getByRole('button', {name: 'Continuar a los detalles'}).click();
  await expect(submit).toBeDisabled();
  await page.getByRole('button', {name: 'Guardar borrador'}).click();
  await expect(submit).toBeEnabled();
  await submit.click();
  await expect(page.getByText('Solicitud enviada y pendiente de revisión.', {exact: false})).toBeVisible();
  await expect(submit).toHaveCount(0);
  expect(calls).toEqual(['quote', 'create', 'update', 'submit']);
});

test('lector activo puede cotizar sin acceso a la captura', async ({page}) => {
  const calls = await mockCapture(page, []);
  await page.goto('/portal/laboratory-requests');
  await chooseTime(page);
  await expect(page.getByRole('button', {name: 'Continuar a los detalles'})).toHaveCount(0);
  expect(calls).toEqual(['quote']);
});

test('API real protege lectura y cotización con sesión y CSRF', async ({request}) => {
  expect((await request.get(root)).status()).toBe(401);
  expect((await request.post(root + '/availability-quotes', {data: {}})).status()).toBe(419);
});
