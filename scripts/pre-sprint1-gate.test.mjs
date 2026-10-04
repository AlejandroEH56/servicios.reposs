import test from 'node:test';
import assert from 'node:assert/strict';
import { evaluate, requiredIds, requiredChecks } from './pre-sprint1-gate.mjs';

const sha = 'a'.repeat(40);
const now = Date.parse('2026-10-04T20:00:00Z');
function fixture() {
  const evidence = { sha, exitCode: 0, command: 'test', tool: 'runner', version: '1', environment: 'isolated', executedAt: '2026-10-04T19:00:00Z', artifact: 'artifacts/test.log', sha256: 'b'.repeat(64) };
  return { commit: sha, requiredBlockers: Object.fromEntries(requiredIds.map(id => [id, 'VERIFIED'])), evidence: Object.fromEntries(requiredIds.map(id => [id, [{ ...evidence }]])), requiredChecks: Object.fromEntries(requiredChecks.map(name => [name, { ...evidence, status: 'VERIFIED' }])) };
}
const context = { sha, clean: true, now, artifactMatches: () => true };
test('complete current evidence passes', () => assert.deepEqual(evaluate(fixture(), context), []));
for (const variant of ['missing', 'partial', 'stale', 'sha', 'failed', 'checksum', 'dirty', 'requiredCheck']) {
  test(`rejects ${variant}`, () => {
    const manifest = fixture();
    const ctx = { ...context };
    if (variant === 'missing') delete manifest.requiredBlockers.B01;
    if (variant === 'partial') manifest.requiredBlockers.B01 = 'PARTIAL';
    if (variant === 'stale') manifest.evidence.B01[0].executedAt = '2026-09-01T19:00:00Z';
    if (variant === 'sha') manifest.evidence.B01[0].sha = 'c'.repeat(40);
    if (variant === 'failed') manifest.evidence.B01[0].exitCode = 1;
    if (variant === 'checksum') ctx.artifactMatches = () => false;
    if (variant === 'dirty') ctx.clean = false;
    if (variant === 'requiredCheck') delete manifest.requiredChecks.mysql;
    assert.ok(evaluate(manifest, ctx).length > 0);
  });
}
