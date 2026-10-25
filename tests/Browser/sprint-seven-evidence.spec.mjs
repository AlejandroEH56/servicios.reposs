import { test, expect } from '@playwright/test';
const identity = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const residentId = '01ARZ3NDEKTSV4RRFFQ69G5FAW';
const requirementId = '01ARZ3NDEKTSV4RRFFQ69G5FAX';
const evidenceId = '01ARZ3NDEKTSV4RRFFQ69G5FAY';
const stagedId = '01ARZ3NDEKTSV4RRFFQ69G5FAZ';
async function scenario(page, role) {
  const global = role !== 'resident';
  const permissions = ['residencies.files.read', ...(role === 'resident' ? ['residencies.evidence.submit'] : role === 'admin' ? ['residencies.requirements.manage'] : ['residencies.evidence.review'])];
  const resident = { id: residentId, version: 1, kind: 'residents', name: 'Persona Piloto', controlNumber: '99009901', status: 'ACTIVO' };
  const core = { active: true, allowedMimes: ['text/plain'], code: 'PROOF', displayOrder: 0, maxBytes: 10485760, name: 'Constancia de ensayo', programId: null, required: true, scope: 'RESIDENTE', stage: 'PRERREQUISITO', validFrom: null, validUntil: null };
  let requirement = { id: requirementId, version: 1, ...core, canSubmit: role === 'resident', latestEvidence: null };
  let evidence = role === 'reviewer' ? { id: evidenceId, version: 1, residentId, projectId: null, requirementId, submissionVersion: 1, status: 'ENVIADO', reviewNumber: 0, requirementSnapshot: { id: requirementId, version: 1, ...core }, originalName: '<img src=x onerror=alert(1)>.txt', mime: 'text/plain', size: 13, sha256: 'a'.repeat(64), submittedAt: '2026-10-24T12:00:00Z', replacedAt: null, canReview: true, reviews: [] } : null;
  const calls = { uploads: 0, submissions: 0, reviews: 0, creates: 0, edits: 0 };
  await page.route('**/api/v1/me', route => route.fulfill({ json: { data: { id: identity, displayName: 'Identidad de ensayo', email: null, status: 'ACTIVA', roles: [], permissions, scopes: permissions.map(p => p + (global ? ':global' : ':self')), sessionExpiresAt: new Date(Date.now() + 300000).toISOString() } } }));
  await page.route('**/api/v1/residencies/**', async route => {
    const request = route.request(); const path = new URL(request.url()).pathname.replace('/api/v1/residencies/', '');
    if (request.method() === 'GET') {
      if (path === 'me') return route.fulfill({ json: { data: { resident: global ? null : resident, projects: { items: [], nextCursor: null } } } });
      if (path === 'residents') return route.fulfill({ json: { data: { items: [resident], nextCursor: null } } });
      if (path === 'programs' || path === 'projects') return route.fulfill({ json: { data: { items: [], nextCursor: null } } });
      if (path === 'requirements' || path === 'files/' + residentId + '/requirements') return route.fulfill({ json: { data: { items: [requirement], nextCursor: null } } });
      if (path === 'requirements/' + requirementId) return route.fulfill({ json: { data: requirement } });
      if (path === 'review-queue') return route.fulfill({ json: { data: { items: evidence?.canReview ? [evidence] : [], nextCursor: null } } });
      if (path === 'evidence/' + evidenceId) return route.fulfill({ json: { data: evidence } });
      if (path.endsWith('/history')) return route.fulfill({ json: { data: { items: evidence ? [evidence] : [], nextCursor: null } } });
    }
    expect(request.headers()['x-xsrf-token']).toBeTruthy();
    if (request.method() === 'POST') expect(request.headers()['idempotency-key']).toBeTruthy();
    if (path === 'files/staged') {
      expect(request.headers()['content-type']).toContain('multipart/form-data');
      const body = request.postData(); expect(body).toContain('RESIDENCY_EVIDENCE'); expect(body).toContain(residentId); expect(body).toContain(requirementId); calls.uploads++;
      return route.fulfill({ status: 201, json: { data: { id: stagedId, residentId, projectId: null, requirementId, requirementVersion: requirement.version, status: 'READY', originalName: 'proof.txt', mime: 'text/plain', size: 13, sha256: 'a'.repeat(64), expiresAt: '2026-12-31T12:00:00Z' } } });
    }
    const body = request.postDataJSON();
    if (path === 'evidence') {
      expect(body).toEqual({ residentId, projectId: null, requirementId, stagedFileId: stagedId, expectedSubmissionVersion: 0 }); calls.submissions++;
      evidence = { id: evidenceId, version: 1, residentId, projectId: null, requirementId, submissionVersion: 1, status: 'ENVIADO', reviewNumber: 0, requirementSnapshot: { id: requirementId, version: 1, ...core }, originalName: 'proof.txt', mime: 'text/plain', size: 13, sha256: 'a'.repeat(64), submittedAt: new Date().toISOString(), replacedAt: null, canReview: false, reviews: [] };
      requirement = { ...requirement, latestEvidence: evidence };
      return route.fulfill({ status: 201, json: { data: evidence } });
    }
    if (path === 'requirements' && request.method() === 'POST') {
      expect(body.code).toBe('S7-PILOTO'); expect(body.allowedMimes).toContain('text/plain'); expect(body.version).toBeUndefined(); calls.creates++;
      requirement = { id: requirementId, version: 1, ...body };
      return route.fulfill({ status: 201, json: { data: requirement } });
    }
    if (path === 'requirements/' + requirementId && request.method() === 'PATCH') {
      expect(request.headers()['if-match']).toBe('"1"'); expect(body.name).toBe('Constancia actualizada'); expect(body.latestEvidence).toBeUndefined(); calls.edits++;
      requirement = { id: requirementId, version: 2, ...body };
      return route.fulfill({ json: { data: requirement } });
    }
    if (path === 'evidence/' + evidenceId + '/reviews') {
      expect(body.expectedReviewNumber).toBe(1); expect(body.decision).toBe('RECHAZADO'); expect(body.observations).toBe('Corrección de ensayo'); calls.reviews++;
      const review = { id: stagedId, reviewNumber: 1, previousStatus: 'ENVIADO', decision: body.decision, observations: body.observations, grade: null, decidedAt: new Date().toISOString() };
      evidence = { ...evidence, version: 2, reviewNumber: 1, status: 'RECHAZADO', canReview: false, reviews: [review] };
      return route.fulfill({ status: 201, json: { data: evidence } });
    }
    throw new Error('Unexpected evidence request: ' + request.method() + ' ' + path);
  });
  return calls;
}
test('residente comprueba y envía documento propio con CSRF e historial', async ({ page }) => {
  const calls = await scenario(page, 'resident'); await page.goto('/portal/residencies/evidence');
  await page.getByRole('button', { name: 'PROOF · Constancia de ensayo', exact: true }).click();
  await page.getByLabel('Archivo de evidencia', { exact: true }).setInputFiles({ name: 'proof.txt', mimeType: 'text/plain', buffer: Buffer.from('test document') });
  await page.getByRole('button', { name: 'Comprobar archivo', exact: true }).click();
  await expect(page.locator('body')).toContainText('Archivo comprobado: proof.txt');
  await page.getByRole('button', { name: 'Enviar evidencia', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Historial documental', exact: true })).toBeVisible();
  await expect(page.locator('body')).toContainText('ENVIADO');
  expect(calls.uploads).toBe(1); expect(calls.submissions).toBe(1);
  await expect(page.getByRole('option', { name: 'Administración de requisitos', exact: true })).toHaveCount(0);
});
test('administración crea y edita requisito con su versión actual', async ({ page }) => {
  const calls = await scenario(page, 'admin'); await page.goto('/portal/residencies/evidence');
  await page.getByRole('combobox', { name: 'Área', exact: true }).selectOption('definitions');
  await page.getByLabel('Código', { exact: true }).fill('S7-PILOTO');
  await page.getByLabel('Nombre del requisito', { exact: true }).fill('Constancia piloto Sprint 7');
  await page.getByRole('checkbox', { name: 'Texto plano', exact: true }).check();
  await page.getByRole('button', { name: 'Guardar requisito', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Editar requisito', exact: true })).toBeVisible();
  await page.getByLabel('Nombre del requisito', { exact: true }).fill('Constancia actualizada');
  await page.getByRole('button', { name: 'Guardar requisito', exact: true }).click();
  await expect(page.getByRole('button', { name: 'S7-PILOTO · Constancia actualizada', exact: true })).toBeVisible();
  expect(calls.creates).toBe(1); expect(calls.edits).toBe(1);
});
test('revisión exige observaciones y conserva la decisión sin interpretar el nombre como HTML', async ({ page }) => {
  const calls = await scenario(page, 'reviewer'); await page.goto('/portal/residencies/evidence');
  await page.getByRole('button', { name: /Constancia de ensayo · <img/ }).click();
  await expect(page.locator('img')).toHaveCount(0);
  await page.getByRole('combobox', { name: 'Decisión', exact: true }).selectOption('RECHAZADO');
  await page.getByRole('button', { name: 'Registrar revisión', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('Indica observaciones'); expect(calls.reviews).toBe(0);
  await page.getByLabel('Observaciones', { exact: true }).fill('Corrección de ensayo');
  await page.getByRole('button', { name: 'Registrar revisión', exact: true }).click();
  await expect(page.locator('body')).toContainText('Revisión 1: RECHAZADO');
  await expect(page.getByRole('button', { name: 'Registrar revisión', exact: true })).toHaveCount(0); expect(calls.reviews).toBe(1);
});
test('API documental real rechaza anónimos y cargas o decisiones sin CSRF', async ({ request }) => {
  for (const path of ['requirements', 'files/' + residentId + '/requirements', 'review-queue', 'evidence/' + evidenceId + '/history', 'evidence/' + evidenceId + '/download']) expect((await request.get('/api/v1/residencies/' + path)).status()).toBe(401);
  for (const path of ['files/staged', 'evidence', 'evidence/' + evidenceId + '/reviews', 'requirements']) expect((await request.post('/api/v1/residencies/' + path, { data: {} })).status()).toBe(419);
});
