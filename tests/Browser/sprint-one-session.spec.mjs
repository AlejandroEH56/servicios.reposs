import { test, expect } from '@playwright/test';

function identity(sessionExpiresAt = new Date(Date.now() + 300_000).toISOString()) {
  return { data: { id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', displayName: 'Persona de ensayo', email: null,
    status: 'ACTIVA', roles: [], permissions: [], scopes: [], sessionExpiresAt } };
}

async function mockSession(page, response) {
  await page.route('**/api/v1/me', route => route.fulfill({
    status: response.status, contentType: 'application/json', body: JSON.stringify(response.body ?? {}),
  }));
}

test('guard de dashboard anónimo muestra login sin redirección automática al proveedor', async ({ page }) => {
  await mockSession(page, { status: 401 });
  await page.goto('/portal/dashboard');
  await expect(page).toHaveURL(/\/portal\/login$/);
  await expect(page.getByRole('link', { name: 'Ingresar con Microsoft' })).toHaveAttribute('href', '/auth/entra/login');
  expect(await page.evaluate(() => ({ local: localStorage.length, session: sessionStorage.length }))).toEqual({ local: 0, session: 0 });
});

test('callback de UI obtiene sesión y logout usa el CSRF real del backend', async ({ page }) => {
  let active = true;
  await page.route('**/api/v1/me', route => route.fulfill({ status: active ? 200 : 401,
    contentType: 'application/json', body: JSON.stringify(active ? identity() : {}) }));
  await page.route('**/auth/entra/logout', async route => {
    expect(route.request().method()).toBe('POST');
    expect(route.request().headers()['x-xsrf-token']).toBeTruthy();
    active = false;
    await route.fulfill({ status: 204 });
  });
  await page.goto('/portal/session');
  await expect(page).toHaveURL(/\/portal\/dashboard$/);
  await expect(page.getByText('Bienvenido, Persona de ensayo.')).toBeVisible();
  await page.getByRole('button', { name: 'Cerrar sesión' }).click();
  await expect(page).toHaveURL(/\/portal\/login$/);
  await expect(page.getByText('Bienvenido, Persona de ensayo.')).not.toBeVisible();
});

test('403 muestra acceso denegado', async ({ page }) => {
  await mockSession(page, { status: 403 });
  await page.goto('/portal/dashboard');
  await expect(page.getByRole('heading', { name: 'Acceso denegado' })).toBeVisible();
  await expect(page).toHaveURL(/\/portal\/access-denied$/);
});

test('fallo del servicio muestra reintento sin afirmar que falta autorización', async ({ page }) => {
  await mockSession(page, { status: 503 });
  await page.goto('/portal/dashboard');
  await expect(page.getByRole('heading', { name: 'No podemos comprobar tu sesión' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Reintentar' })).toBeVisible();
});

test('expiración retira la identidad del shell', async ({ page }) => {
  const deadline = new Date(Date.now() + 4000).toISOString();
  await mockSession(page, { status: 200, body: identity(deadline) });
  await page.goto('/portal/session');
  await expect(page.getByText('Bienvenido, Persona de ensayo.')).toBeVisible();
  await expect(page).toHaveURL(/\/portal\/login$/, { timeout: 10_000 });
});

test('mutación same-origin sin CSRF sigue rechazada en el servidor real', async ({ request }) => {
  const response = await request.post('/auth/entra/logout', { headers: {
    Accept: 'application/json', 'Sec-Fetch-Site': 'same-origin',
  }, maxRedirects: 0 });
  expect(response.status()).toBe(419);
  expect(response.headers()['content-type']).toContain('application/problem+json');
  expect((await response.json()).code).toBe('CSRF_TOKEN_MISMATCH');
});

test('callback Entra inválido falla cerrado y bootstrap CSRF no autentica', async ({ request }) => {
  expect((await request.get('/auth/entra/callback?state=invalid&code=invalid')).status()).toBe(419);
  const csrf = await request.get('/sanctum/csrf-cookie');
  expect(csrf.status()).toBe(204);
  expect((await request.get('/api/v1/me')).status()).toBe(401);
});
