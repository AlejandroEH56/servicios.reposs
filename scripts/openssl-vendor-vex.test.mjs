import test from 'node:test';
import assert from 'node:assert/strict';
import { vendorVex } from './openssl-vendor-vex.mjs';

const time = new Date('2026-11-18T12:00:00Z');
function image(version = '3.5.9-r101') {
  return {source: {type: 'image', metadata: {manifestDigest: 'sha256:' + 'a'.repeat(64), tags: ['servicios-backend:fixture']}},
    artifacts: ['libcrypto3', 'libssl3', 'openssl'].map(name => ({name, type: 'apk', version,
      purl: 'pkg:apk/alpine/' + name + '@' + version + '?arch=x86_64&distro=alpine-3.24.4'}))};
}
test('unpatched revisions and unrelated products have no fixed statements', () => {
  assert.deepEqual(vendorVex(image('3.5.9-r0'), time).statements, []);
  assert.deepEqual(vendorVex(image('3.5.9-r1'), time).statements, []);
  assert.deepEqual(vendorVex({artifacts: []}, time).statements, []);
});
test('fixed statements bind nine upstream fixes to exact corrected APK package identifiers', () => {
  const result = vendorVex(image(), time);
  assert.equal(result.statements.length, 9);
  for (const statement of result.statements) {
    assert.equal(statement.status, 'fixed');
    assert.equal(statement.products.length, 3);
    assert.ok(statement.products.every(p => p['@id'].includes('@3.5.9-r101?')));
    assert.ok(statement.status_notes.includes('sha256:' + 'a'.repeat(64)));
  }
});
test('partial installs, missing provenance, foreign images and expired reviews fail closed', () => {
  const partial = image(); partial.artifacts.pop(); partial.artifacts.pop();
  assert.throws(() => vendorVex(partial, time), /Incomplete/);
  const unknown = image(); delete unknown.source.metadata.manifestDigest;
  assert.throws(() => vendorVex(unknown, time), /digest/);
  const foreign = image(); foreign.source.metadata.tags = ['foreign:fixture'];
  assert.throws(() => vendorVex(foreign, time), /Unexpected/);
  const mixed = image(); mixed.artifacts[0].purl = 'pkg:apk/alpine/libcrypto3@3.5.9-r0';
  assert.throws(() => vendorVex(mixed, time), /Unexpected/);
  assert.throws(() => vendorVex(image(), new Date('2026-12-08T00:00:00Z')), /expired/);
});
