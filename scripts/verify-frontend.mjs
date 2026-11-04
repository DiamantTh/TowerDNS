// SPDX-License-Identifier: AGPL-3.0-or-later
import assert from 'node:assert/strict';
import { readFileSync, statSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { brotliDecompressSync, zstdDecompressSync } from 'node:zlib';
import { join } from 'node:path';

const manifest = JSON.parse(readFileSync('build/asset-manifest.json', 'utf8'));
const release = JSON.parse(readFileSync('build/asset-release.json', 'utf8'));
const main = 'themes/default/src/app/main.ts';
const auth = 'themes/default/src/app/AuthRoot.svelte';
const shell = 'themes/default/src/app/App.svelte';
const publicRoot = 'themes/default/src/app/PublicRoot.svelte';
const page = name => `themes/default/src/app/pages/${name}Pages.svelte`;
const pageNames = ['Security', 'Dashboard', 'Dns', 'Profile', 'Invitation', 'Provider', 'Settings', 'Management'];
for (const key of [main, auth, shell, publicRoot, ...pageNames.map(page)]) assert.ok(manifest[key], `Missing module ${key}`);
assert.ok(manifest[main].dynamicImports.includes(auth), 'Login root must be loaded dynamically');
assert.ok(manifest[main].dynamicImports.includes(shell), 'Authenticated shell must be loaded dynamically');
assert.ok(manifest[main].dynamicImports.includes(publicRoot), 'Public security shell must be loaded dynamically');
function staticClosure(key, seen = new Set()) {
    if (seen.has(key)) return seen;
    seen.add(key);
    for (const dep of manifest[key].imports ?? []) staticClosure(dep, seen);
    return seen;
}
const login = staticClosure(auth, staticClosure(main));
for (const key of [shell, publicRoot, ...pageNames.map(page)]) assert.ok(!login.has(key), `Login statically loads ${key}`);
assert.ok((manifest[auth].dynamicImports ?? []).some(key => manifest[key].file.startsWith('main-')), 'Password estimator must stay dynamic');
for (const name of pageNames) assert.ok(manifest[page(name)].isDynamicEntry, `${name} pages must be a dynamic entry`);
for (const [name, expected] of Object.entries(release.assets)) {
    const bytes = readFileSync(join('httpdocs/assets', name));
    assert.equal(createHash('sha256').update(bytes).digest('hex'), expected, `Incorrect asset ${name}`);
    if (!/\.(js|css)$/.test(name)) continue;
    assert.deepEqual(brotliDecompressSync(readFileSync(join('httpdocs/assets', `${name}.br`))), bytes, `Incorrect Brotli asset ${name}`);
    assert.deepEqual(zstdDecompressSync(readFileSync(join('httpdocs/assets', `${name}.zst`))), bytes, `Incorrect Zstandard asset ${name}`);
}
const names = [manifest['themes/default/src/app/theme-init.ts'].file, ...manifest[main].css, ...[...login].map(key => manifest[key].file)];
let raw = 0, br = 0, zstd = 0;
for (const name of [...new Set(names)]) {
    const path = join('httpdocs/assets', name);
    raw += statSync(path).size; br += statSync(`${path}.br`).size; zstd += statSync(`${path}.zst`).size;
    console.log(`${name}: raw=${statSync(path).size} br=${statSync(`${path}.br`).size} zstd=${statSync(`${path}.zst`).size}`);
}
console.log(`Login initial assets: requests=${new Set(names).size} raw=${raw} br=${br} zstd=${zstd}`);
console.log('Frontend manifest graph and compressed variants verified.');
