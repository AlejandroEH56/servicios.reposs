import { test, expect } from '@playwright/test';

const origin = process.env.E2E_BASE_URL ?? 'https://localhost:8443';

test('Angular se sirve por el mismo origen TLS que la API', async ({ page }) => {
  const response = await page.goto('/portal/');
  expect(response.status()).toBe(200);
  await expect(page.getByRole('heading', { name: 'Servicios', exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Ingresar con Microsoft' })).toHaveAttribute('href', '/auth/microsoft');
  expect(new URL(page.url()).origin).toBe(origin);
});

test('cookies de sesión son Secure/HttpOnly/Lax y host-only', async ({ page, context }) => {
  await page.goto('/login');
  const cookies = await context.cookies();
  const session = cookies.find(cookie => cookie.name === 'laravel_session');
  expect(session).toBeDefined();
  expect(session.secure).toBe(true);
  expect(session.httpOnly).toBe(true);
  expect(session.sameSite).toBe('Lax');
  expect(session.domain).toBe('localhost');
  expect(await page.evaluate(() => document.cookie.includes('laravel_session='))).toBe(false);
});

test('logout rechaza CSRF ausente y acepta XSRF de la misma sesión', async ({ page, context }) => {
  await page.goto('/login');
  const invalid = await context.request.post('/auth/logout', { maxRedirects: 0 });
  expect(invalid.status()).toBe(419);
  const xsrf = (await context.cookies()).find(cookie => cookie.name === 'XSRF-TOKEN');
  expect(xsrf).toBeDefined();
  const valid = await context.request.post('/auth/logout', {
    headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value) }, maxRedirects: 0,
  });
  expect(valid.status()).toBe(302);
  expect(new URL(valid.headers().location).pathname).toBe('/login');
});

test('callback inválido, API anónima y health usan los controles previstos', async ({ request }) => {
  expect((await request.get('/auth/microsoft/callback?state=invalid&code=invalid')).status()).toBe(419);
  const me = await request.get('/api/v1/me');
  expect(me.status()).toBe(401);
  expect(me.headers()['content-type']).toContain('application/problem+json');
  expect((await me.json()).code).toBe('AUTHENTICATION_REQUIRED');
  expect((await request.get('/health/live')).status()).toBe(200);
  expect((await request.get('/health/ready')).status()).toBe(200);
});

test('redirección OIDC tiene callback TLS, PKCE S256, nonce y state', async ({ request }) => {
  const response = await request.get('/auth/microsoft', { maxRedirects: 0 });
  expect(response.status()).toBe(302);
  const authorization = new URL(response.headers().location);
  expect(authorization.origin).toBe('https://login.microsoftonline.com');
  expect(authorization.searchParams.get('redirect_uri')).toBe(origin + '/auth/microsoft/callback');
  expect(authorization.searchParams.get('code_challenge_method')).toBe('S256');
  expect(authorization.searchParams.get('state').length).toBe(64);
  expect(authorization.searchParams.get('nonce').length).toBe(64);
  expect(authorization.searchParams.get('code_challenge')).toMatch(/^[A-Za-z0-9_-]{43}$/);
});
