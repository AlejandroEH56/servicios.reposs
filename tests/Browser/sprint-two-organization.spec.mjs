import { test, expect } from '@playwright/test';

const id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const roleId = '01ARZ3NDEKTSV4RRFFQ69G5FAW';
const targetId = '01ARZ3NDEKTSV4RRFFQ69G5FAX';
async function session(page, permissions, scopes = []) {
  await page.route('**/api/v1/me', route => route.fulfill({ json: { data: {
    id, displayName: 'Persona de ensayo', email: null, status: 'ACTIVA', roles: [], permissions, scopes,
    sessionExpiresAt: new Date(Date.now() + 300_000).toISOString(),
  } } }));
}

test('una cuenta activa sin permisos no puede abrir las pantallas del Sprint 2', async ({ page }) => {
  await session(page, []);
  for (const path of ['iam', 'organization']) {
    await page.goto(`/portal/${path}`);
    await expect(page).toHaveURL(/\/access-denied$/);
    await expect(page.getByRole('heading', { name: 'Acceso denegado' })).toBeVisible();
  }
});

test('IAM crea un rol, otorga y revoca explícitamente con CSRF y versiones', async ({ page }) => {
  await session(page, ['iam.roles.consultar', 'iam.roles.gestionar']);
  const roles = [{ id: roleId, code: 'EMPLEADO', name: 'Empleado', version: 1, permissions: [] }];
  let version = 1;
  let assigned = false;
  let created = false;
  const history = [];
  await page.route('**/api/v1/iam/**', async route => {
    const request = route.request(); const path = new URL(request.url()).pathname;
    if (request.method() !== 'GET') { expect(request.headers()['x-xsrf-token']).toBeTruthy(); }
    if (path.endsWith('/permissions')) { return route.fulfill({ json: { data: [] } }); }
    if (path.endsWith('/identities')) { return route.fulfill({ json: { data: [{ id: targetId, displayName: 'Identidad de ensayo', status: 'ACTIVA', authorizationVersion: version }] } }); }
    if (path === '/api/v1/iam/roles') {
      if (request.method() === 'POST') {
        expect(request.postDataJSON()).toEqual({ code: 'PILOTO_UI', name: 'Rol de ensayo' });
        created = true;
        const role = { id: '01ARZ3NDEKTSV4RRFFQ69G5FAY', code: 'PILOTO_UI', name: 'Rol de ensayo', version: 1, permissions: [] };
        roles.push(role); return route.fulfill({ status: 201, json: { data: role } });
      }
      return route.fulfill({ json: { data: roles } });
    }
    if (request.method() !== 'GET') {
      expect(request.postDataJSON()).toEqual({ expectedAuthorizationVersion: version });
      assigned = request.method() === 'POST'; version++;
      history.push({ id: `event-${version}`, roleId, action: assigned ? 'GRANTED' : 'REVOKED', occurredAt: new Date().toISOString(), authorizationVersion: version });
    }
    return route.fulfill({ json: { data: { identityId: targetId, authorizationVersion: version,
      roles: assigned ? [{ id: roleId, code: 'EMPLEADO', expiresAt: null }] : [], history } } });
  });
  await page.goto('/portal/iam');
  await page.getByLabel('Código', { exact: true }).fill('PILOTO_UI');
  await page.getByLabel('Nombre', { exact: true }).fill('Rol de ensayo');
  await page.getByRole('button', { name: 'Crear rol', exact: true }).click();
  await expect.poll(() => created).toBe(true);
  await page.getByRole('combobox', { name: 'Identidad', exact: true }).selectOption(targetId);
  await expect(page.getByText('Sin roles asignados.')).toBeVisible();
  await page.getByRole('combobox', { name: 'Rol por otorgar', exact: true }).selectOption(roleId);
  await page.getByRole('button', { name: 'Otorgar rol' }).click();
  await expect(page.getByRole('button', { name: 'Revocar EMPLEADO' })).toBeVisible();
  await page.getByRole('button', { name: 'Revocar EMPLEADO' }).click();
  await expect(page.getByText('Sin roles asignados.')).toBeVisible();
  expect(history.map(event => event.action)).toEqual(['GRANTED', 'REVOKED']);
});

test('el empleado gestiona grados propios sin editar puestos Microsoft ni mostrar administraciones', async ({ page }) => {
  await session(page, ['organization.empleados.consultar', 'organization.grados.gestionar', 'organization.niveles.consultar'], ['organization.grados.gestionar:self']);
  const employee = { id: targetId, identityId: id, employeeNumber: null, givenNames: 'Persona', firstSurname: 'Ensayo', secondSurname: null,
    email: null, isAdviser: false, status: 'ACTIVO', version: 1, profileVersion: 1,
    positions: [{ id: 'position', unitId: null, unitName: null, name: 'Docente Microsoft', source: 'MICROSOFT_JOBTITLE', order: 1, startsOn: '2026-01-01', endsOn: null, active: true }], degrees: [] };
  let added = false; let removed = false;
  await page.route('**/api/v1/organization/**', async route => {
    const request = route.request(); const path = new URL(request.url()).pathname;
    if (path.endsWith('/levels')) { return route.fulfill({ json: { data: [{ id: roleId, code: 'LIC', name: 'Licenciatura', rank: 1, version: 1 }] } }); }
    if (path.endsWith('/employees')) { return route.fulfill({ json: { data: [employee] } }); }
    if (request.method() !== 'GET') {
      expect(request.headers()['x-xsrf-token']).toBeTruthy();
      expect(request.postDataJSON().expectedVersion).toBe(employee.version);
      if (request.method() === 'POST') {
        added = true; expect(request.postDataJSON().levelId).toBe(roleId);
        employee.degrees.push({ id: 'degree', levelId: roleId, levelName: 'Licenciatura', program: 'Programa de ensayo', abbreviation: null, obtainedOn: null });
      } else { removed = true; employee.degrees = []; }
      employee.version++;
    }
    return route.fulfill({ json: { data: employee } });
  });
  await page.goto('/portal/organization');
  await page.getByRole('button', { name: 'Persona Ensayo', exact: true }).click();
  await expect(page.getByText('Docente Microsoft', { exact: false })).toBeVisible();
  await expect(page.getByRole('button', { name: /Finalizar/ })).toHaveCount(0);
  await expect(page.getByText('Registrar empleado', { exact: true })).toHaveCount(0);
  await page.getByRole('combobox', { name: 'Nivel', exact: true }).selectOption(roleId);
  await page.getByLabel('Programa', { exact: true }).fill('Programa de ensayo');
  await page.getByRole('button', { name: 'Agregar grado', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Retirar Programa de ensayo' })).toBeVisible();
  await page.getByRole('button', { name: 'Retirar Programa de ensayo' }).click();
  await expect(page.getByText('Sin grados.')).toBeVisible();
  expect(added && removed).toBe(true);
});

test('API real del Sprint 2 rechaza anónimos y escritura sin CSRF', async ({ request }) => {
  for (const path of ['iam/roles', 'iam/permissions', 'iam/identities', 'organization/employees', 'organization/units', 'organization/levels', 'organization/advisers']) {
    expect((await request.get(`/api/v1/${path}`)).status()).toBe(401);
  }
  expect((await request.post('/api/v1/iam/roles', { data: { code: 'INVALID', name: 'Anonymous' }, headers: { 'Sec-Fetch-Site': 'same-origin' } })).status()).toBe(419);
  expect((await request.post('/api/v1/organization/units', { data: {} })).status()).toBe(419);
});
