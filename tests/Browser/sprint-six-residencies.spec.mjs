import { test, expect } from '@playwright/test';

const id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const companyId = '01ARZ3NDEKTSV4RRFFQ69G5FAW';
const periodId = '01ARZ3NDEKTSV4RRFFQ69G5FAX';
const projectId = '01ARZ3NDEKTSV4RRFFQ69G5FAY';
const assignmentId = '01ARZ3NDEKTSV4RRFFQ69G5FAZ';
const personal = ['residencies.files.read', 'residencies.profile.manage', 'residencies.projects.manage', 'residencies.advisers.assign', 'residencies.companies.create', 'residencies.companies.manage'];

test('administración inscribe su identidad y recibe indicaciones de campos sin perder los datos', async ({ page }) => {
  const permissions = [...personal, 'residencies.residents.enroll'];
  let resident = null; let attempts = 0;
  await page.route('**/api/v1/me', route => route.fulfill({ json: { data: { id, displayName: 'Administración de ensayo', email: null, status: 'ACTIVA', roles: ['ADMIN_RESIDENCIAS'], permissions, scopes: permissions.map(permission => permission + ':global'), sessionExpiresAt: new Date(Date.now() + 300000).toISOString() } } }));
  await page.route('**/api/v1/residencies/**', async route => {
    const request = route.request(); const path = new URL(request.url()).pathname.replace('/api/v1/residencies/', '');
    if (request.method() === 'POST') {
      expect(path).toBe('residents'); expect(request.headers()['x-xsrf-token']).toBeTruthy(); expect(request.headers()['idempotency-key']).toBeTruthy();
      const body = request.postDataJSON(); expect(body.identityId).toBe(id); expect(body.controlNumber).toBe('99009901'); expect(body.programId).toBe(id); expect(body.email).toBeUndefined();
      attempts++;
      if (attempts === 1) return route.fulfill({ status: 422, json: { code: 'VALIDATION_ERROR', invalidFields: ['controlNumber'] } });
      resident = { ...body, id: companyId, kind: 'residents', version: 1, name: 'Persona Piloto', status: 'ACTIVO' };
      return route.fulfill({ status: 201, json: { data: resident } });
    }
    if (path === 'me') return route.fulfill({ json: { data: { resident, projects: { items: [], nextCursor: null } } } });
    if (path === 'identity-candidates') return route.fulfill({ json: { data: { items: [{ id, displayName: 'Administración de ensayo', status: 'ACTIVA' }], nextCursor: null } } });
    if (path === 'programs') return route.fulfill({ json: { data: { items: [{ id, kind: 'programs', version: 1, name: 'Programa de ensayo', active: true }], nextCursor: null } } });
    if (path === 'residents') return route.fulfill({ json: { data: { items: resident ? [resident] : [], nextCursor: null } } });
    if (path === 'residents/' + companyId) return route.fulfill({ json: { data: resident } });
    throw new Error('Unexpected enrollment request');
  });
  await page.goto('/portal/residencies');
  await page.getByRole('combobox', { name: 'Identidad institucional', exact: true }).selectOption(id);
  await page.getByRole('combobox', { name: 'Programa educativo', exact: true }).selectOption(id);
  await page.getByLabel('Número de control (8 o 9 dígitos)', { exact: true }).fill('corto');
  await page.getByLabel('Nombres', { exact: true }).fill('Persona');
  await page.getByLabel('Primer apellido', { exact: true }).fill('Piloto');
  await page.getByRole('button', { name: 'Registrar', exact: true }).click();
  await expect(page.getByRole('list', { name: 'Campos por corregir' })).toContainText('Número de control'); expect(attempts).toBe(0);
  await page.getByLabel('Número de control (8 o 9 dígitos)', { exact: true }).fill('99009901');
  await page.getByRole('button', { name: 'Registrar', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('Revisa los campos');
  await expect(page.getByRole('list', { name: 'Campos por corregir' })).toContainText('Número de control');
  await expect(page.getByLabel('Nombres', { exact: true })).toHaveValue('Persona');
  await page.getByRole('button', { name: 'Registrar', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Persona Piloto · versión 1 · ACTIVO', exact: true })).toBeVisible();
  await expect(page.getByRole('list', { name: 'Campos por corregir' })).toHaveCount(0); expect(attempts).toBe(2);
});

async function mockResidencies(page, scenario) {
  const permissions = scenario === 'reader' ? ['residencies.files.read'] : personal;
  const resident = { id, kind: 'residents', version: 1, name: '<img src=x onerror=alert(1)>', givenNames: 'Persona', firstSurname: 'Piloto', controlNumber: '21000001', programId: id, status: 'ACTIVO' };
  let company = scenario === 'company' ? null : { id: companyId, kind: 'companies', version: 1, name: 'Empresa de ensayo', rfc: 'XAXX010101000', status: 'ACTIVO', canManage: false, canReadContacts: scenario === 'project' };
  let project = { id: projectId, kind: 'projects', version: 1, residentId: id, companyId, periodId, programId: id, name: 'Proyecto de ensayo', vacancies: 1, startsOn: '2026-08-01', endsOn: '2026-12-31', status: 'BORRADOR', internalAdvisers: [], externalAdvisers: [] };
  const contacts = [];
  const calls = { companies: 0, contacts: 0, assignments: 0, endings: 0, privateContactReads: 0 };
  await page.route('**/api/v1/me', route => route.fulfill({ json: { data: { id, displayName: 'Identidad de ensayo', email: null, status: 'ACTIVA', roles: [], permissions, scopes: permissions.map(permission => permission + ':self'), sessionExpiresAt: new Date(Date.now() + 300000).toISOString() } } }));
  await page.route('**/api/v1/residencies/**', async route => {
    const request = route.request(); const path = new URL(request.url()).pathname.replace('/api/v1/residencies/', '');
    if (request.method() === 'GET') {
      if (path === 'me') return route.fulfill({ json: { data: { resident, projects: { items: scenario === 'project' ? [project] : [], nextCursor: null } } } });
      if (path === 'adviser-candidates') return route.fulfill({ json: { data: { items: [{ id, displayName: 'Asesor de ensayo', positions: [], degrees: [] }], nextCursor: null } } });
      if (path.endsWith('/contacts')) { calls.privateContactReads++; return route.fulfill({ json: { data: { items: contacts, nextCursor: null } } }); }
      const segments = path.replace('catalogs/', '').split('/');
      const rows = { residents: [resident], companies: company ? [company] : [], projects: scenario === 'project' ? [project] : [], programs: [{ id, kind: 'programs', version: 1, name: 'Programa de ensayo', modalityId: id, active: true }], periods: [{ id: periodId, kind: 'periods', version: 1, name: 'Periodo de ensayo', startsOn: '2026-08-01', endsOn: '2026-12-31', status: 'ACTIVO' }], modalities: [{ id, kind: 'modalities', version: 1, name: 'Modalidad', active: true }], sectors: [], 'business-lines': [] };
      expect(rows[segments[0]]).toBeDefined();
      return route.fulfill({ json: { data: segments.length === 1 ? { items: rows[segments[0]], nextCursor: null } : rows[segments[0]].find(row => row.id === segments[1]) } });
    }
    expect(request.headers()['x-xsrf-token']).toBeTruthy();
    const body = request.postDataJSON();
    if (path === 'companies' && request.method() === 'POST') {
      expect(request.headers()['idempotency-key']).toBeTruthy(); expect(body.ownerId).toBeUndefined(); calls.companies++;
      company = { ...body, id: companyId, kind: 'companies', version: 1, canManage: true, canReadContacts: true };
      return route.fulfill({ status: 201, json: { data: company } });
    }
    if (path === 'companies/' + companyId + '/contacts' && request.method() === 'POST') {
      expect(request.headers()['idempotency-key']).toBeTruthy(); calls.contacts++;
      const contact = { ...body, id: assignmentId, companyId, kind: 'contacts', version: 1, name: body.givenNames + ' ' + body.firstSurname }; contacts.push(contact);
      return route.fulfill({ status: 201, json: { data: contact } });
    }
    if (path === 'projects/' + projectId + '/internal-advisers' && request.method() === 'POST') {
      expect(request.headers()['if-match']).toBe('"1"'); expect(request.headers()['idempotency-key']).toBeTruthy(); expect(body.nameSnapshot).toBeUndefined(); calls.assignments++;
      const assignment = { id: assignmentId, kind: 'internal-advisers', version: 1, projectId, employeeId: id, roleCode: body.roleCode, nameSnapshot: 'Instantánea institucional', positionSnapshot: null, degreeSnapshot: null, startsOn: body.startsOn, endsOn: body.endsOn };
      project = { ...project, version: 2, internalAdvisers: [assignment] };
      return route.fulfill({ status: 201, json: { data: { ...assignment, projectVersion: 2 } } });
    }
    if (path.endsWith('/internal-advisers/' + assignmentId) && request.method() === 'PATCH') {
      expect(request.headers()['if-match']).toBe('"2"'); expect(Object.keys(body)).toEqual(['endsOn']); calls.endings++;
      const assignment = { ...project.internalAdvisers[0], endsOn: body.endsOn, version: 2 }; project = { ...project, version: 3, internalAdvisers: [assignment] };
      return route.fulfill({ json: { data: { ...assignment, projectVersion: 3 } } });
    }
    throw new Error('Unexpected residency mutation: ' + request.method() + ' ' + path);
  });
  return calls;
}

test('lector de expediente ve texto plano y no obtiene editor ni contactos ajenos', async ({ page }) => {
  const calls = await mockResidencies(page, 'reader');
  await page.goto('/portal/residencies');
  await expect(page.getByRole('heading', { name: 'Residencias profesionales', exact: true })).toBeVisible();
  await expect(page.locator('body')).toContainText('21000001'); await expect(page.locator('img')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Guardar cambios', exact: true })).toHaveCount(0);
  await page.getByRole('combobox', { name: 'Sección', exact: true }).selectOption('companies');
  await page.getByRole('button', { name: 'Empresa de ensayo', exact: true }).click();
  await expect(page.getByRole('heading', { name: /Empresa de ensayo/ })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Contactos de la empresa', exact: true })).toHaveCount(0);
  expect(calls.privateContactReads).toBe(0);
});

test('residente registra empresa y contacto propios con CSRF y claves idempotentes', async ({ page }) => {
  const calls = await mockResidencies(page, 'company'); await page.goto('/portal/residencies');
  await page.getByRole('combobox', { name: 'Sección', exact: true }).selectOption('companies');
  await page.getByLabel('Razón social', { exact: true }).fill('Empresa piloto Sprint 6');
  await page.getByLabel('RFC (único e inmutable)', { exact: true }).fill('XAXX010101000');
  await page.getByRole('button', { name: 'Registrar', exact: true }).click();
  await expect(page.getByRole('heading', { name: /Empresa piloto Sprint 6 · versión 1/ })).toBeVisible();
  await expect(page.getByLabel('RFC (único e inmutable)', { exact: true })).toBeDisabled();
  await page.getByLabel('Nombres del contacto', { exact: true }).fill('Contacto');
  await page.getByLabel('Primer apellido del contacto', { exact: true }).fill('Ensayo');
  await page.getByRole('button', { name: 'Guardar contacto', exact: true }).click();
  await expect(page.locator('body')).toContainText('Contacto Ensayo'); expect(calls.companies).toBe(1); expect(calls.contacts).toBe(1);
});

test('asesoría usa versión del proyecto y conserva instantánea al finalizar', async ({ page }) => {
  const calls = await mockResidencies(page, 'project'); await page.goto('/portal/residencies');
  await page.getByRole('combobox', { name: 'Sección', exact: true }).selectOption('projects');
  await page.getByRole('button', { name: 'Proyecto de ensayo', exact: true }).click();
  await page.getByRole('combobox', { name: 'Asesor', exact: true }).selectOption(id);
  await page.getByRole('button', { name: 'Asignar asesor', exact: true }).click();
  await expect(page.getByRole('heading', { name: /Proyecto de ensayo · versión 2/ })).toBeVisible();
  await expect(page.locator('body')).toContainText('Instantánea institucional');
  await page.getByLabel('Fin de asignación de Instantánea institucional', { exact: true }).fill('2026-09-30');
  await page.getByRole('button', { name: 'Finalizar asignación', exact: true }).click();
  await expect(page.getByRole('heading', { name: /Proyecto de ensayo · versión 3/ })).toBeVisible();
  await expect(page.locator('body')).toContainText('2026-09-30'); await expect(page.locator('body')).toContainText('Instantánea institucional');
  expect(calls.assignments).toBe(1); expect(calls.endings).toBe(1);
});

test('API real de Residencias rechaza anónimos y mutaciones sin CSRF', async ({ request }) => {
  for (const path of ['me', 'residents/' + id, 'projects/' + projectId, 'companies/' + companyId + '/contacts']) expect((await request.get('/api/v1/residencies/' + path)).status()).toBe(401);
  expect((await request.post('/api/v1/residencies/companies', { data: {} })).status()).toBe(419);
  expect((await request.patch('/api/v1/residencies/projects/' + projectId, { data: {} })).status()).toBe(419);
  expect((await request.post('/api/v1/residencies/projects/' + projectId + '/internal-advisers', { data: {} })).status()).toBe(419);
});
