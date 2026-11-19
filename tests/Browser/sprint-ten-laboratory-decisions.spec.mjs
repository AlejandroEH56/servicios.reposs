import { test, expect } from '@playwright/test';

const actor = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const owner = '01ARZ3NDEKTSV4RRFFQ69G5FAW';
const root = '/api/v1/laboratory-requests';
const requestId = '01ARZ3NDEKTSV4RRFFQ69G5FAX';

async function workspace(page, { reviewer = true, own = false, conflict = false } = {}) {
  let row = {id: requestId, ownerId: own ? actor : owner, laboratoryId: actor, semesterId: actor, type: 'VARIA',
    title: 'Solicitud de ensayo <script>alert(1)</script>', purpose: 'Actividad académica', attendees: 1, supplies: null,
    startAt: '2027-01-04T15:00:00Z', endAt: '2027-01-04T16:00:00Z', status: 'PENDIENTE', version: 2,
    submittedAt: '2027-01-01T15:00:00Z', various: {useCode: 'PROYECTO', description: 'Actividad de ensayo'}};
  let history = [];
  let receiptReady = false;
  const calls = [];
  await page.route('**/api/v1/me', route => route.fulfill({json: {data: {
    id: actor, displayName: 'Revisor de ensayo', status: 'ACTIVA', email: null, roles: [],
    permissions: reviewer ? ['laboratorios.solicitudes.revisar'] : [], scopes: [],
    sessionExpiresAt: new Date(Date.now() + 300000).toISOString(),
  }}}));
  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({status: 204,
    headers: {'Set-Cookie': 'XSRF-TOKEN=fixture-sprint10; Path=/; SameSite=Lax'}}));
  await page.route('**/api/v1/academic-planning/laboratories*', route => route.fulfill({json: {
    data: {items: [{id: actor, name: 'Laboratorio de ensayo', active: true, capacity: 25}], nextCursor: null},
  }}));
  await page.route(/\/api\/v1\/laboratory-requests(?:[/?]|$)/, async route => {
    const request = route.request(), path = new URL(request.url()).pathname;
    const reply = data => route.fulfill({json: {data}});
    if (request.method() === 'GET') {
      if (path.endsWith('/review-queue')) return reply({items: row.status === 'PENDIENTE' ? [row] : [], nextCursor: null});
      if (path === root + '/workflow') return reply({items: own ? [row] : [], nextCursor: null});
      if (path.endsWith('/workflow')) return reply(row);
      if (path.endsWith('/decisions')) return reply({items: history});
      if (path.endsWith('/notifications')) return reply({items: [], nextCursor: null});
      if (path.endsWith('/calendar')) return reply({laboratoryId: actor, timeZone: 'America/Mexico_City',
        items: row.status === 'AUTORIZADO' ? [{startAt: row.startAt, endAt: row.endAt, status: 'OCUPADO'}] : []});
      if (path.endsWith('/receipt/file')) return route.fulfill({contentType: 'application/pdf',
        headers: {'Content-Disposition': 'attachment; filename=comprobante.pdf'}, body: '%PDF-1.4\n%%EOF'});
      if (path.endsWith('/receipt')) return reply({decisionId: actor, requestId, requestStatus: row.status,
        status: receiptReady ? 'LISTO' : 'PENDIENTE', sha256: null, size: null});
      throw new Error('Unexpected GET ' + path);
    }
    expect(request.headers()['x-xsrf-token']).toBeTruthy();
    expect(request.headers()['idempotency-key']).toBeTruthy();
    expect(request.headers()['if-match']).toBe('"' + row.version + '"');
    if (conflict) return route.fulfill({status: 409, json: {code: 'CONFLICT'}});
    const body = request.postDataJSON();
    expect(body.reason.trim().length).toBeGreaterThanOrEqual(3);
    const status = path.endsWith('/cancel') ? 'CANCELADO' : body.decision;
    calls.push(status);
    history.push({id: actor + calls.length, status, previousStatus: row.status,
      reason: body.reason, version: row.version + 1, decidedAt: '2027-01-01T16:00:00Z'});
    row = {...row, status, version: row.version + 1};
    receiptReady = false;
    return reply(row);
  });
  return {calls, ready: () => { receiptReady = true; }};
}

test('reviewer decides with a reason, receives a PDF and cancellation clears occupancy', async ({page}) => {
  const state = await workspace(page);
  await page.goto('/portal/laboratory-requests/review');
  await page.getByRole('button', {name: /Solicitud de ensayo/}).click();
  await expect(page.getByRole('button', {name: 'Resolver solicitud', exact: true})).toBeDisabled();
  await page.getByLabel('Motivo de decisión o cancelación').fill('Horario y capacidad comprobados');
  await page.getByRole('button', {name: 'Resolver solicitud', exact: true}).click();
  await expect(page.getByText('PENDIENTE → AUTORIZADO', {exact: false})).toBeVisible();
  await expect(page.getByRole('button', {name: 'Descargar comprobante PDF'})).toHaveCount(0);
  state.ready();
  await page.getByRole('button', {name: 'Actualizar comprobante'}).click();
  const download = page.waitForEvent('download');
  await page.getByRole('button', {name: 'Descargar comprobante PDF'}).click();
  expect((await download).suggestedFilename()).toMatch(/comprobante/);
  await page.getByLabel('Laboratorio', {exact: true}).selectOption(actor);
  await page.getByLabel('Fecha de ocupación', {exact: true}).fill('2027-01-04');
  await page.getByRole('button', {name: 'Consultar ocupación'}).click();
  await expect(page.getByText(/^OCUPADO:/)).toBeVisible();
  await page.getByLabel('Motivo de decisión o cancelación').fill('Actividad cancelada por ensayo');
  await page.getByRole('button', {name: 'Cancelar solicitud', exact: true}).click();
  await expect(page.getByText('No hay ocupación autorizada', {exact: false})).toBeVisible();
  expect(state.calls).toEqual(['AUTORIZADO', 'CANCELADO']);
  await expect(page.locator('script:has-text("alert(1)")')).toHaveCount(0);
});

test('request owner can cancel but cannot decide even with a reviewer role', async ({page}) => {
  await workspace(page, {own: true});
  await page.goto('/portal/laboratory-requests/review?requestId=' + requestId);
  await expect(page.getByRole('heading', {name: /Solicitud de ensayo/})).toBeVisible();
  await expect(page.getByRole('button', {name: 'Resolver solicitud', exact: true})).toHaveCount(0);
  await expect(page.getByRole('button', {name: 'Cancelar solicitud', exact: true})).toBeDisabled();
  await page.getByLabel('Motivo de decisión o cancelación').fill('Cancelación propia de ensayo');
  await page.getByRole('button', {name: 'Cancelar solicitud', exact: true}).click();
  await expect(page.getByText('PENDIENTE → CANCELADO', {exact: false})).toBeVisible();
});

test('non-reviewers have no review queue and a conflict refreshes rather than deciding twice', async ({page}) => {
  await workspace(page, {reviewer: false, own: true, conflict: true});
  await page.goto('/portal/laboratory-requests/review?requestId=' + requestId);
  await expect(page.getByRole('heading', {name: 'Cola de revisión', exact: true})).toHaveCount(0);
  await page.getByLabel('Motivo de decisión o cancelación').fill('Intento con versión obsoleta');
  await page.getByRole('button', {name: 'Cancelar solicitud', exact: true}).click();
  await expect(page.getByRole('alert')).toContainText('El registro cambió o existe un conflicto');
  await expect(page.getByRole('heading', {name: /Solicitud de ensayo/})).toBeVisible();
});

test('real workflow routes require a session and decision mutations require CSRF', async ({request}) => {
  expect((await request.get(root + '/workflow')).status()).toBe(401);
  expect((await request.get(root + '/review-queue')).status()).toBe(401);
  expect((await request.post(root + '/' + requestId + '/decisions', {data: {decision: 'AUTORIZADO', reason: 'Ensayo'}})).status()).toBe(419);
  expect((await request.post(root + '/' + requestId + '/cancel', {data: {reason: 'Ensayo'}})).status()).toBe(419);
});
