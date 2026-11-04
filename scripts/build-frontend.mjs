// SPDX-License-Identifier: AGPL-3.0-or-later
import { build } from 'vite';
import { createHash, randomBytes } from 'node:crypto';
import { mkdtempSync, mkdirSync, readFileSync, readdirSync, writeFileSync, linkSync, unlinkSync, renameSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, relative, sep } from 'node:path';
import { brotliCompressSync, brotliDecompressSync, zstdCompressSync, zstdDecompressSync, constants } from 'node:zlib';

const root = process.cwd();
const assetsDir = join(root, 'httpdocs/assets');
const releaseDir = join(root, 'build');
const stage = mkdtempSync(join(tmpdir(), 'towerdns-frontend-'));
const watch = process.argv.includes('--watch');
const digest = bytes => createHash('sha256').update(bytes).digest('hex');

if (!zstdCompressSync || !zstdDecompressSync) throw new Error('Node.js with Zstandard support is required (Node.js 22.15 or newer).');

function filesUnder(directory) {
    return readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
        const path = join(directory, entry.name);
        return entry.isDirectory() ? filesUnder(path) : entry.isFile() ? [path] : [];
    });
}

// Publish each immutable filename at most once. Never overwrite a file that an
// older browser or PHP release may still use.
function publishFile(name, bytes, verifyExisting) {
    const target = join(assetsDir, name);
    const temp = join(assetsDir, `.${name}.${process.pid}.${randomBytes(6).toString('hex')}.tmp`);
    writeFileSync(temp, bytes, { flag: 'wx', mode: 0o644 });
    try {
        try { linkSync(temp, target); }
        catch (error) {
            if (error.code !== 'EEXIST') throw error;
            verifyExisting(readFileSync(target));
        }
    } finally { unlinkSync(temp); }
    return readFileSync(target);
}

function atomicWrite(path, bytes) {
    const temp = `${path}.${process.pid}.${randomBytes(6).toString('hex')}.tmp`;
    writeFileSync(temp, bytes, { flag: 'wx', mode: 0o644 });
    renameSync(temp, path);
}

function publish() {
    const manifestBytes = readFileSync(join(stage, '.vite/manifest.json'));
    const manifest = JSON.parse(manifestBytes.toString('utf8'));
    const files = filesUnder(stage).filter(path => !relative(stage, path).startsWith(`.vite${sep}`));
    const names = new Set(files.map(path => relative(stage, path).split(sep).join('/')));
    for (const [key, entry] of Object.entries(manifest)) {
        for (const name of [entry.file, ...(entry.css ?? []), ...(entry.assets ?? [])]) {
            if (!names.has(name)) throw new Error(`Manifest entry ${key} references missing asset ${name}`);
        }
        for (const dep of [...(entry.imports ?? []), ...(entry.dynamicImports ?? [])]) {
            if (!manifest[dep]) throw new Error(`Manifest entry ${key} references missing module ${dep}`);
        }
    }
    for (const key of ['themes/default/src/app/main.ts', 'themes/default/src/app/theme-init.ts']) {
        if (!manifest[key]?.isEntry) throw new Error(`Missing frontend entry ${key}`);
    }
    mkdirSync(assetsDir, { recursive: true });
    mkdirSync(releaseDir, { recursive: true });
    const inventory = {};
    for (const file of files.sort()) {
        const name = relative(stage, file).split(sep).join('/');
        if (name.includes('/') || !/^[A-Za-z0-9_.-]+$/.test(name)) throw new Error(`Unsupported asset path ${name}`);
        const original = readFileSync(file);
        const actual = publishFile(name, original, existing => {
            if (!existing.equals(original)) throw new Error(`Content collision for immutable asset ${name}`);
        });
        inventory[name] = digest(actual);
        if (/\.(js|css)$/.test(name)) {
            const variants = [
                ['br', brotliCompressSync(original, { params: { [constants.BROTLI_PARAM_QUALITY]: 11 } }), brotliDecompressSync],
                ['zst', zstdCompressSync(original), zstdDecompressSync],
            ];
            for (const [suffix, bytes, decompress] of variants) {
                const variant = `${name}.${suffix}`;
                const published = publishFile(variant, bytes, existing => {
                    if (!decompress(existing).equals(original)) throw new Error(`Invalid immutable asset ${variant}`);
                });
                inventory[variant] = digest(published);
            }
        }
    }
    const sourcePaths = [join(root, 'package.json'), join(root, 'package-lock.json'), join(root, 'vite.config.ts'), join(root, 'svelte.config.js'), join(root, 'tsconfig.json'), ...filesUnder(join(root, 'themes/default/src'))].sort();
    const sources = Object.fromEntries(sourcePaths.map(path => [relative(root, path).split(sep).join('/'), digest(readFileSync(path))]));
    const phpPaths = ['src', 'httpdocs', 'install'].flatMap(dir => filesUnder(join(root, dir))).filter(path => path.endsWith('.php')).sort();
    const phpSources = Object.fromEntries(phpPaths.map(path => [relative(root, path).split(sep).join('/'), digest(readFileSync(path))]));
    const release = JSON.stringify({ manifestSha256: digest(manifestBytes), assets: inventory, sources, phpSources }, null, 2) + '\n';
    // A mismatch between the two release-local files fails closed in PHP.
    // Production switches their containing release directory atomically.
    atomicWrite(join(releaseDir, 'asset-manifest.json'), manifestBytes);
    atomicWrite(join(releaseDir, 'asset-release.json'), release);
    console.log(`Published ${files.length} immutable assets and release-local manifest; retained older assets.`);
}

try {
    const result = await build({ build: { outDir: stage, ...(watch ? { watch: {} } : {}) } });
    if (watch) {
        result.on('event', event => {
            if (event.code === 'BUNDLE_END') {
                try { publish(); } catch (error) { console.error('Frontend publication failed:', error); }
                event.result.close();
            } else if (event.code === 'ERROR') console.error(event.error);
        });
        for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, async () => { await result.close(); rmSync(stage, { recursive: true, force: true }); process.exit(); });
    } else publish();
} finally {
    if (!watch) rmSync(stage, { recursive: true, force: true });
}
