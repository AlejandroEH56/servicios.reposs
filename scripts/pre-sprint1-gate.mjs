import { readFileSync, existsSync, realpathSync } from 'node:fs';
import { resolve, relative, isAbsolute } from 'node:path';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export const requiredIds = [...Array.from({ length: 15 }, (_, i) => `B${String(i + 1).padStart(2, '0')}`), ...Array.from({ length: 5 }, (_, i) => `BN-${String(i + 1).padStart(2, '0')}`)];
export const requiredChecks = ['backend', 'mysql', 'frontend', 'openapi', 'security', 'supply-chain', 'branch-rules'];

export function evaluate(manifest, context) {
  const failures = [];
  const states = new Set(['VERIFIED', 'PARTIAL', 'BLOCKED_INFO', 'READY_TO_FIX', 'OPEN', 'MISSING', 'NOT_RUN', 'FAIL']);
  if (!/^[0-9a-f]{40}$/.test(context.sha ?? '') || manifest.commit !== context.sha) failures.push('SHA actual no coincide con manifest');
  if (!context.clean) failures.push('Working tree sin versionar: ejecutar evidencia sobre commit limpio');
  function validEvidence(item) {
    if (!item || item.sha !== context.sha || item.exitCode !== 0 || !item.command || !item.tool || !item.version || !item.environment) return false;
    const age = context.now - Date.parse(item.executedAt);
    if (!Number.isFinite(age) || age < 0 || age > 7 * 86400000) return false;
    return /^[0-9a-f]{64}$/.test(item.sha256 ?? '') && context.artifactMatches(item.artifact, item.sha256);
  }
  for (const id of requiredIds) {
    const state = manifest.requiredBlockers?.[id];
    if (!states.has(state)) failures.push(`${id}: estado ausente o inválido`);
    else if (state !== 'VERIFIED') failures.push(`${id}: ${state}`);
    const evidence = manifest.evidence?.[id];
    if (!Array.isArray(evidence) || evidence.length === 0 || !evidence.every(validEvidence)) failures.push(`${id}: evidencia inválida/ausente/vencida`);
  }
  for (const name of requiredChecks) {
    const check = manifest.requiredChecks?.[name];
    if (check?.status !== 'VERIFIED' || !validEvidence(check)) failures.push(`required check ${name}: no verificado`);
  }
  return failures;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
  let failures;
  try {
    const manifest = JSON.parse(readFileSync(resolve(root, process.argv[2] ?? 'docs/implementacion/ESTADO_LOCAL_REPOSS.json'), 'utf8').replace(/^\uFEFF/, ''));
    const sha = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim();
    const clean = execFileSync('git', ['status', '--porcelain'], { cwd: root, encoding: 'utf8' }).trim() === '';
    failures = evaluate(manifest, { sha, clean, now: Date.now(), artifactMatches(path, hash) {
      if (typeof path !== 'string' || !path.startsWith('artifacts/')) return false;
      const target = resolve(root, path);
      if (!existsSync(target)) return false;
      const rel = relative(realpathSync(resolve(root, 'artifacts')), realpathSync(target));
      if (rel.startsWith('..') || isAbsolute(rel)) return false;
      return createHash('sha256').update(readFileSync(target)).digest('hex') === hash;
    } });
  } catch {
    failures = ['Manifest o contexto Git inválido'];
  }
  console.log(failures.length ? 'NO-GO' : 'GO');
  failures.forEach(failure => console.log(failure));
  process.exitCode = failures.length ? 1 : 0;
}
