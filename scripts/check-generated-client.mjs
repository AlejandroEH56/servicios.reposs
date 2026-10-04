import { readFileSync, readdirSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const frontend = join(root, 'frontend');
const redocly = JSON.parse(readFileSync(join(root, 'node_modules/@redocly/cli/package.json'), 'utf8'));
execFileSync(process.execPath, [join(root, 'node_modules/@redocly/cli', redocly.bin.redocly), 'bundle', 'api', '--output', 'artifacts/api.yaml'], { cwd: root, stdio: 'inherit' });
const pkg = JSON.parse(readFileSync(join(frontend, 'node_modules/ng-openapi-gen/package.json'), 'utf8'));
const bin = typeof pkg.bin === 'string' ? pkg.bin : pkg.bin['ng-openapi-gen'];
const generated = join(root, 'artifacts/generated-check');
execFileSync(process.execPath, [join(frontend, 'node_modules/ng-openapi-gen', bin), '--config', 'ng-openapi-gen.json', '--output', generated], { cwd: frontend, stdio: 'inherit' });
function hashes(folder, prefix = '') {
  const result = {};
  for (const entry of readdirSync(folder, { withFileTypes: true })) {
    const relative = `${prefix}${entry.name}`;
    if (entry.isDirectory()) Object.assign(result, hashes(join(folder, entry.name), `${relative}/`));
    else result[relative] = createHash('sha256').update(readFileSync(join(folder, entry.name))).digest('hex');
  }
  return result;
}
const expected = hashes(join(frontend, 'src/app/api'));
const actual = hashes(generated);
const differences = [...new Set([...Object.keys(expected), ...Object.keys(actual)])].filter(path => expected[path] !== actual[path]);
if (differences.length) {
  console.error('Cliente generado desactualizado:', differences.join(', '));
  process.exitCode = 1;
} else console.log('Cliente Angular reproducible: PASS');
