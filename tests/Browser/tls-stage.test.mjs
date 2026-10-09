import test from 'node:test';
import assert from 'node:assert/strict';
import https from 'node:https';
import { readFileSync } from 'node:fs';

const origin = process.env.E2E_BASE_URL ?? 'https://localhost:8443';
const ca = readFileSync(process.env.E2E_CA_FILE ?? '.tools/caddy-data/pki/authorities/local/root.crt');
const expectedReadiness = Number(process.env.EXPECTED_READINESS ?? '200');
assert.ok([200, 503].includes(expectedReadiness));

function request(path, method = 'GET') {
  return new Promise((resolve, reject) => {
    const req = https.request(new URL(path, origin), { ca, method, rejectUnauthorized: true, timeout: 10000 }, response => {
      const authorized = response.socket.authorized;
      let body = '';
      response.setEncoding('utf8');
      response.on('data', chunk => { body += chunk; });
      response.on('end', () => resolve({ status: response.statusCode, headers: response.headers, body, authorized }));
    });
    req.on('timeout', () => req.destroy(new Error('TLS smoke timed out')));
    req.on('error', reject);
    req.end();
  });
}

test('TLS verifica cadena y hostname y health está disponible', async () => {
  for (const path of ['/health/live', '/health/ready', '/portal/']) {
    const response = await request(path);
    assert.equal(response.authorized, true);
    assert.equal(response.status, path === '/health/ready' ? expectedReadiness : 200);
  }
});

test('sesión HTTPS y CSRF negativo en servidor real', async () => {
  const login = await request('/login');
  assert.equal(login.status, 200);
  const cookie = login.headers['set-cookie'].find(value => value.startsWith('laravel_session='));
  assert.ok(cookie);
  assert.match(cookie, /; secure/i);
  assert.match(cookie, /; httponly/i);
  assert.match(cookie, /; samesite=lax/i);
  assert.equal((await request('/auth/logout', 'POST')).status, 419);
  const me = await request('/api/v1/me');
  assert.equal(me.status, 401);
  assert.match(me.headers['content-type'], /application\/problem\+json/);
  assert.equal(JSON.parse(me.body).code, 'AUTHENTICATION_REQUIRED');
});
