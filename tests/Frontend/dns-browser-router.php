<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (is_string($path) && preg_match('~^/assets/([A-Za-z0-9_-]+-[A-Za-z0-9_-]{8}\.(?:js|css))$~', $path, $match)) {
    $_GET['asset'] = $match[1];
    require $root . '/httpdocs/asset.php';
    return;
}
if ($path === '/tlsa/generate' || $path === '/accounts/42/zones/7/tlsa') {
    require $root . '/vendor/autoload.php';
    $tag    = '"' . str_repeat('a', 64) . '"';
    $status = ['records' => ['3 1 1 ' . str_repeat('c', 64)], 'ttl' => 600, 'conditional' => true, 'csrf_token' => 'change-fixture'];
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $certificates = $_POST['certificates'] ?? null;
    if ($path === '/tlsa/generate' && ($_POST['csrf_token'] ?? null) === 'preview-fixture' && is_string($certificates)) {
        $generator = new TowerDNS\Application\DNS\TlsaGenerator();
        echo json_encode(['records' => $generator->generate($certificates, 3, 1, 1), 'csrf_token' => 'preview-next'], JSON_THROW_ON_ERROR);
        return;
    }
    if ($path === '/accounts/42/zones/7/tlsa' && ($_GET['owner'] ?? null) === '_443._tcp') {
        if (($_SERVER['REQUEST_METHOD'] ?? null) === 'POST') {
            $change  = $_POST['change'] ?? null;
            $decoded = is_string($change) ? json_decode($change, true) : null;
            if (($_SERVER['HTTP_IF_MATCH'] ?? null)                                                                !== $tag || ($_POST['csrf_token'] ?? null) !== 'change-fixture'
                                                                                                                            || $decoded !== ['add' => ['3 1 1 57a5102f89d9420924abe803e29c1a90107fefc0de414ceda90a1460503bc604'], 'remove' => [], 'ttl' => 300]) {
                http_response_code(412);
                echo '{"error":"unexpected_change"}';
                return;
            }
        }
        header('ETag: ' . $tag);
        echo json_encode($status, JSON_THROW_ON_ERROR);
        return;
    }
    http_response_code(400);
    echo '{"error":"invalid_fixture_request"}';
    return;
}
if ($path !== '/fixture') {
    http_response_code(404);
    return;
}
require $root . '/vendor/autoload.php';
$requestedPage = $_GET['page'] ?? null;
$page          = is_string($requestedPage) ? $requestedPage : 'zones/list';
$readonly      = isset($_GET['readonly']);
$props         = ['csrfToken' => 'fixture', 'user' => new TowerDNS\Domain\Auth\User('fixture', 'fixture@example.test'), 'accountId' => 42];
if ($page === 'zones/list') {
    $props += ['canManageProviders' => !$readonly, 'providerFilter' => is_string($_GET['provider'] ?? null) ? $_GET['provider'] : '', 'canCreateZones' => !$readonly, 'canDeleteZones' => !$readonly, 'providerAccounts' => [], 'providerNames' => ['1' => 'Provider A', '2' => 'Provider B'], 'managedZones' => [
        ['id' => 3, 'canonicalName' => 'z.example', 'providerAccountId' => 2],
        ['id' => 2, 'canonicalName' => 'a.example', 'providerAccountId' => 1],
        ['id' => 1, 'canonicalName' => 'b.example', 'providerAccountId' => 2],
    ]];
} elseif ($page === 'zones/records') {
    $props += ['tlsaPreviewToken' => 'preview-fixture', 'managedZoneId' => 7, 'managedZoneName' => 'a.example', 'canReplaceRrsets' => !$readonly, 'canDeleteRrsets' => !$readonly, 'recordTypes' => TowerDNS\Domain\DNS\DNSRecordType::knownPresentations(), 'rrsets' => [
        ['ownerName' => 'www', 'type' => ['presentation' => 'A', 'code' => 1, 'isKnown' => true], 'ttl' => 600, 'rdata' => ['192.0.2.1', '192.0.2.2']],
        ['ownerName' => '_domainkey', 'type' => ['presentation' => 'TXT', 'code' => 16, 'isKnown' => true], 'ttl' => 300, 'rdata' => ['"v=DKIM1; p=' . str_repeat('a', 4000) . '"']],
    ]];
} else {
    $props += ['managedZoneId' => 7, 'managedZoneName' => 'a.example', 'profile' => ['state' => 'signed', 'features' => ['manual_actions' => !$readonly]]];
}
$translator = new Laminas\I18n\Translator\Translator();
$translator->setLocale(($_GET['locale'] ?? '') === 'de-DE' ? 'de-DE' : 'en-GB');
$translator->addTranslationFilePattern('phpArray', $root . '/translations', '%s.php');
header('Cache-Control: no-store');
echo new TowerDNS\Infrastructure\Ui\SvelteRenderer(new TowerDNS\Application\Theme\ThemeManager($root), translator: $translator)->render('app::' . $page, $props);
