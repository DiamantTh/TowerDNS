<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
// Run only after asset retention has expired, with deployments stopped.
$root = dirname(__DIR__);
$id = $argv[1] ?? '';
if (preg_match('/\A[0-9a-f]{64}\z/D', $id) !== 1) throw new RuntimeException('Expected a release record ID.');
$dir = $root . '/httpdocs/assets/.releases';
$path = $dir . '/' . $id . '.json';
$read = static function (string $file): array {
    $raw = file_get_contents($file);
    if (!is_string($raw) || !hash_equals(basename($file, '.json'), hash('sha256', $raw))) throw new RuntimeException('Invalid release record.');
    $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $release = json_decode($record['release'], true, 512, JSON_THROW_ON_ERROR);
    return $release['assets'];
};
$active = json_decode((string) @file_get_contents($root . '/build/asset-activation.json'), true);
if (($active['id'] ?? null) === $id) throw new RuntimeException('Cannot prune the active release.');
$assets = $read($path);
$others = [];
foreach (glob($dir . '/*.json') ?: [] as $other) {
    if ($other !== $path) $others[] = $read($other);
}
foreach ($assets as $name => $hash) {
    if (!is_file($root . '/httpdocs/assets/' . $name)) continue;
    $shared = false;
    foreach ($others as $inventory) if (($inventory[$name] ?? null) === $hash) { $shared = true; break; }
    if (!$shared) throw new RuntimeException('Remove retained release assets before pruning their only record: ' . $name);
}
if (!unlink($path)) throw new RuntimeException('Could not remove release record.');
echo "Release record pruned.\n";
