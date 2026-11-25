import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

export function vendorVex(sbom, now = new Date()) {
  if (now >= new Date('2026-12-08T00:00:00Z')) throw new Error('OpenSSL vendor fix review expired; replace with the supported release.');
  const packages = (sbom.artifacts ?? []).filter(pkg => ['libcrypto3', 'libssl3', 'openssl'].includes(pkg.name)
    && pkg.type === 'apk' && pkg.version === '3.5.9-r101');
  if (!packages.length) return document([], now);
  if (!packages.some(pkg => pkg.name === 'libcrypto3') || !packages.some(pkg => pkg.name === 'libssl3')) {
    throw new Error('Incomplete OpenSSL vendor fix installation.');
  }
  const metadata = sbom.source?.metadata;
  if (sbom.source?.type !== 'image' || !/^sha256:[0-9a-f]{64}$/.test(metadata?.manifestDigest ?? '')) {
    throw new Error('An image manifest digest is required for vendor fix VEX.');
  }
  const tag = metadata.tags?.[0]?.replace(/^.*\//, '').replace(/:[^:]+$/, '');
  if (!/^servicios-(?:backend|proxy|antivirus)$/.test(tag ?? '')) throw new Error('Unexpected vendor fix image.');
  const products = packages.map(pkg => {
    if (pkg.purl?.split('?')[0] !== 'pkg:apk/alpine/' + pkg.name + '@3.5.9-r101') throw new Error('Unexpected package identifier.');
    return {'@id': pkg.purl};
  });
  const commits = {
    'CVE-2026-88823': '159e1de0c448d7b9f09ad768c8e141def4aa1eb4',
    'CVE-2026-107496': 'a0051cfe699bf5a047bc1c4faef65f80f7987366',
    'CVE-2026-95692': '257a3427aa0ef8d9dd2ef5b3e2d1c021a221b116',
    'CVE-2026-95691': 'f6244675833e097c2429a40d1b206d95385bd42f',
    'CVE-2026-107493': 'c8f6d04f8e6d40b1bf83334e4a2ec9daf5943a3d',
    'CVE-2026-124709': 'f3acb0a3328046741479482c51cec815839e1b05',
    'CVE-2026-121482': '468c287cf451a834b370b69ab321b8ffdfac23aa',
    'CVE-2026-107494': '734c6cd7444bdbf589bfec917e47598c1b21ddd3',
    'CVE-2026-84786': '1ad851363d5b8588b0d40edcc045fd633e93f0c2',
  };
  return document(Object.entries(commits).map(([cve, commit]) => ({
    vulnerability: {name: cve, '@id': 'https://nvd.nist.gov/vuln/detail/' + cve},
    products, status: 'fixed', timestamp: now.toISOString(),
    status_notes: 'REPOSS OpenSSL 3.5.9-r101 includes https://github.com/openssl/openssl/commit/' + commit
      + '. The pinned APKBUILD verifies all source and patch SHA-512, applies with fuzz=0 and requires the upstream regression suite. Classification uses exact package PURLs; local Docker archives have no repository digest. Scanned manifest: '+metadata.manifestDigest+'. Older revisions are excluded.',
  })), now);
}

function document(statements, now) {
  const digest = createHash('sha256').update(JSON.stringify(statements)).digest('hex');
  return {'@context': 'https://openvex.dev/ns/v0.2.0', '@id': 'https://github.com/AlejandroEH56/servicios.reposs/vex/openssl/' + digest,
    author: 'Servicios REPOSS build', timestamp: now.toISOString(), version: 1, statements};
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const [input, output] = process.argv.slice(2);
  if (!input || !output) throw new Error('Usage: openssl-vendor-vex.mjs <syft-json> <output-vex>');
  writeFileSync(output, JSON.stringify(vendorVex(JSON.parse(readFileSync(input, 'utf8'))), null, 2) + '\n');
}
