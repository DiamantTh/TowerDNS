// SPDX-License-Identifier: AGPL-3.0-or-later
import { spawnSync } from 'node:child_process';
import { chmodSync, cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, renameSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';

const root = process.cwd();
const requestedOutput = process.argv[2];
const git = run('git', ['rev-parse', '--short=12', 'HEAD'], root).trim();
const output = resolve(root, requestedOutput ?? `dist/towerdns-${git}.tar.gz`);
const partialOutput = `${output}.partial-${process.pid}`;
const temp = mkdtempSync(join(tmpdir(), 'towerdns-package-'));
const stage = join(temp, 'towerdns');

function run(command, args, cwd, options = {}) {
    const result = spawnSync(command, args, { cwd, encoding: 'utf8', stdio: options.stdio ?? 'pipe', env: options.env ?? process.env });
    if (result.status !== 0) {
        if (result.stdout) process.stderr.write(result.stdout);
        if (result.stderr) process.stderr.write(result.stderr);
        throw new Error(`${command} ${args.join(' ')} failed with exit ${result.status ?? result.signal}`);
    }
    return result.stdout ?? '';
}

function copy(name) {
    const source = join(root, name);
    if (!existsSync(source)) throw new Error(`Required distribution input is missing: ${name}`);
    cpSync(source, join(stage, name), { recursive: true, preserveTimestamps: true, dereference: false });
}

try {
    run('npm', ['run', 'frontend:check'], root, { stdio: 'inherit' });
    run('php', ['scripts/activate-frontend.php'], root, { stdio: 'inherit' });

    mkdirSync(stage, { recursive: true });
    for (const name of [
        'bin', 'src', 'modules', 'templates', 'translations', 'themes', 'httpdocs', 'install', 'scripts', 'vendor',
        'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'vite.config.ts',
        'svelte.config.js', 'tsconfig.json', 'README.md', 'LICENSE',
    ]) copy(name);
    mkdirSync(join(stage, 'build'), { recursive: true });
    mkdirSync(join(stage, 'docs'), { recursive: true });
    copy('docs/INSTALLATION.md');

    const release = JSON.parse(readFileSync(join(root, 'build/asset-release.json'), 'utf8'));
    const activation = JSON.parse(readFileSync(join(root, 'build/asset-activation.json'), 'utf8'));
    const assetNames = Object.keys(release.assets ?? {});
    if (!assetNames.length || !/^[0-9a-f]{64}$/.test(activation.id ?? '')) throw new Error('Frontend release is not activated.');

    const assets = join(stage, 'httpdocs/assets');
    rmSync(assets, { recursive: true, force: true });
    mkdirSync(join(assets, '.releases'), { recursive: true });
    for (const name of assetNames) {
        if (name.includes('/') || name.includes('..')) throw new Error(`Unsafe asset path in release: ${name}`);
        const source = join(root, 'httpdocs/assets', name);
        if (!existsSync(source)) throw new Error(`Release asset is missing: ${name}`);
        cpSync(source, join(assets, name));
    }
    copy('build/asset-manifest.json');
    copy('build/asset-release.json');
    copy('build/asset-activation.json');
    copy(`httpdocs/assets/.releases/${activation.id}.json`);

    for (const directory of ['configs', 'cache', 'data', 'logs']) {
        mkdirSync(join(stage, directory), { recursive: true, mode: 0o750 });
        chmodSync(join(stage, directory), 0o750);
    }

    run('composer', [
        'install', '--no-dev', '--no-interaction', '--no-progress', '--prefer-dist',
        '--optimize-autoloader', '--no-scripts',
    ], stage, { stdio: 'inherit', env: { ...process.env, COMPOSER_DISABLE_NETWORK: '1' } });
    run('php', ['scripts/activate-frontend.php'], stage, { stdio: 'inherit' });

    mkdirSync(dirname(output), { recursive: true });
    const epoch = process.env.SOURCE_DATE_EPOCH ?? '0';
    if (!/^\d+$/.test(epoch)) throw new Error('SOURCE_DATE_EPOCH must be an integer.');
    const tarball = join(temp, 'towerdns.tar');
    run('tar', [
        '--sort=name', `--mtime=@${epoch}`, '--owner=0', '--group=0', '--numeric-owner',
        '--format=posix', '--pax-option=delete=atime,delete=ctime,delete=LIBARCHIVE.creationtime',
        '-cf', tarball, '-C', temp, 'towerdns',
    ], root);
    const compressed = spawnSync('gzip', ['-n', '-9', '-c', tarball], {
        cwd: root,
        encoding: 'buffer',
        maxBuffer: 512 * 1024 * 1024,
    });
    if (compressed.status !== 0) {
        if (compressed.stderr) process.stderr.write(compressed.stderr);
        throw new Error(`gzip -n -9 failed with exit ${compressed.status ?? compressed.signal}`);
    }
    writeFileSync(partialOutput, compressed.stdout, { mode: 0o644 });
    renameSync(partialOutput, output);

    const bytes = statSync(output).size;
    process.stdout.write(`Prepared TowerDNS distribution: ${output} (${bytes} bytes)\n`);
    process.stdout.write('The archive contains production vendor dependencies and an activated, fully verified frontend release.\n');
} finally {
    rmSync(partialOutput, { force: true });
    rmSync(temp, { recursive: true, force: true });
}
