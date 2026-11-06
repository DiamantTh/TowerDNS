<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && preg_match('~^/assets/([A-Za-z0-9_-]+-[A-Za-z0-9_-]{8}\.(?:js|css))$~', $path, $match)) {
    $_GET['asset'] = $match[1];
    require $root . '/httpdocs/asset.php';
    return;
}
if ($path !== '/fixture') { http_response_code(404); return; }
require $root . '/vendor/autoload.php';
$page = $_GET['page'] ?? 'zones/list';
$readonly = isset($_GET['readonly']);
$props = ['csrfToken' => 'fixture', 'user' => new TowerDNS\Domain\Auth\User('fixture', 'fixture@example.test'), 'accountId' => 42];
if ($page === 'zones/list') {
    $props += ['canCreateZones' => !$readonly, 'canDeleteZones' => !$readonly, 'providerAccounts' => [], 'providerNames' => ['1' => 'Provider A', '2' => 'Provider B'], 'managedZones' => [
        ['id' => 3, 'canonicalName' => 'z.example', 'providerAccountId' => 2],
        ['id' => 2, 'canonicalName' => 'a.example', 'providerAccountId' => 1],
        ['id' => 1, 'canonicalName' => 'b.example', 'providerAccountId' => 2],
    ]];
} elseif ($page === 'zones/records') {
    $props += ['managedZoneId' => 7, 'managedZoneName' => 'a.example', 'canReplaceRrsets' => !$readonly, 'canDeleteRrsets' => !$readonly, 'recordTypes' => TowerDNS\Domain\DNS\DNSRecordType::knownPresentations(), 'rrsets' => [
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
echo (new TowerDNS\Infrastructure\Ui\SvelteRenderer(new TowerDNS\Application\Theme\ThemeManager($root), translator: $translator))->render('app::' . $page, $props);
