<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

// Preserve the selected representation and its Content-Length.
@ini_set('zlib.output_compression', '0');

// The web server routes only hashed JS/CSS asset requests here. This endpoint
// also works directly with ?asset= for servers using PHP-FPM.
$name = $_SERVER['TOWERDNS_ASSET_NAME'] ?? $_GET['asset'] ?? '';
if (!is_string($name) || preg_match('/\A[A-Za-z0-9_-]+-[A-Za-z0-9_-]{8}\.(js|css)\z/D', $name, $match) !== 1) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Cache-Control: no-store');
    exit;
}
$original = __DIR__ . '/assets/' . $name;
$expected = null;
foreach (glob(__DIR__ . '/assets/.releases/*.json') ?: [] as $recordPath) {
    $id = basename($recordPath, '.json');
    $raw = @file_get_contents($recordPath);
    if (!is_string($raw) || preg_match('/\A[0-9a-f]{64}\z/D', $id) !== 1 || !hash_equals($id, hash('sha256', $raw))) continue;
    $record = json_decode($raw, true);
    $release = is_array($record) && ($record['schema'] ?? null) === 1 && is_string($record['release'] ?? null) ? json_decode($record['release'], true) : null;
    $assets = is_array($release) ? ($release['assets'] ?? null) : null;
    if (!is_array($assets) || !isset($assets[$name], $assets[$name . '.br'], $assets[$name . '.zst'])) continue;
    $candidate = [$assets[$name], $assets[$name . '.br'], $assets[$name . '.zst']];
    if (count(array_filter($candidate, fn($hash) => is_string($hash) && preg_match('/\A[0-9a-f]{64}\z/D', $hash))) !== 3) continue;
    if ($expected !== null && $expected !== $candidate) {
        error_log('TowerDNS conflicting release records for ' . $name);
        http_response_code(503); header('Cache-Control: no-store'); exit;
    }
    $expected = $candidate;
}
if ($expected === null) {
    http_response_code(404); header('Cache-Control: no-store'); exit;
}
$valid = static function (string $path, string $hash): bool {
    $actual = is_file($path) ? @hash_file('sha256', $path) : false;
    return is_string($actual) && hash_equals($hash, $actual);
};
if (!$valid($original, $expected[0])) {
    error_log('TowerDNS original asset missing or damaged: ' . $name);
    http_response_code(503); header('Cache-Control: no-store'); exit;
}
$brValid = $valid($original . '.br', $expected[1]);
$zstValid = $valid($original . '.zst', $expected[2]);
if (!$brValid || !$zstValid) error_log('TowerDNS compressed asset missing or damaged: ' . $name);

$header = trim((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
$explicit = [];
if ($header !== '') {
    foreach (explode(',', strtolower($header)) as $item) {
        $parts = array_map('trim', explode(';', $item));
        $coding = array_shift($parts);
        if ($coding === null || $coding === '') {
            continue;
        }
        $quality = 1.0;
        foreach ($parts as $part) {
            if (str_starts_with($part, 'q=')) {
                $value = substr($part, 2);
                $quality = preg_match('/\A(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)\z/D', $value) === 1 ? (float) $value : 0.0;
            }
        }
        $explicit[$coding] = max($explicit[$coding] ?? 0.0, $quality);
    }
}
$wildcard = $explicit['*'] ?? 0.0;
$qualities = [
    'br'       => $explicit['br'] ?? $wildcard,
    'zstd'     => $explicit['zstd'] ?? $wildcard,
    'identity' => $explicit['identity'] ?? (($explicit['*'] ?? null) === 0.0 ? 0.0 : 1.0),
];
$encoding = '';
$best = 0.0;
foreach ($qualities as $candidate => $quality) {
    if (($candidate === 'br' && !$brValid) || ($candidate === 'zstd' && !$zstValid)) continue;
    if ($quality > $best) {
        $best = $quality;
        $encoding = $candidate;
    }
}
header('Vary: Accept-Encoding');
if ($encoding === '') {
    http_response_code(406);
    header('Cache-Control: no-store');
    exit;
}
$path = $original . match ($encoding) { 'br' => '.br', 'zstd' => '.zst', default => '' };
$etag = '"' . hash_file('sha256', $path) . '"';
header('Cache-Control: public, max-age=31536000, immutable, no-transform');
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . ($match[1] === 'js' ? 'text/javascript' : 'text/css') . '; charset=utf-8');
header('ETag: ' . $etag);
if ($encoding !== 'identity') {
    header('Content-Encoding: ' . $encoding);
}
$ifNoneMatch = array_map('trim', explode(',', (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')));
if (in_array($etag, $ifNoneMatch, true) || in_array('*', $ifNoneMatch, true)) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . filesize($path));
if ($method === 'GET') {
    readfile($path);
}
