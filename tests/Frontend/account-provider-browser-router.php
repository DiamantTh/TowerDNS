<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && preg_match('~^/assets/([A-Za-z0-9_-]+-[A-Za-z0-9_-]{8}\.(?:js|css))$~', $path, $match)) {
    $_GET['asset'] = $match[1]; require $root . '/httpdocs/asset.php'; return;
}
if ($path !== '/fixture') { http_response_code(404); return; }
require $root . '/vendor/autoload.php';
$readonly = isset($_GET['readonly']);
$account = new TowerDNS\Domain\Account\Account(42, 'Example Account', 'example', 'owner', true, '2026-01-01');
$definitions = [];
foreach (['first', 'second'] as $id) $definitions[$id] = ['label' => ucfirst($id), 'user_managed' => true, 'credentials' => [
    'token' => ['input' => 'token', 'label' => 'Token', 'required' => true, 'secret' => true],
    'url' => ['input' => 'url', 'label' => 'URL', 'required' => true, 'secret' => false, 'default' => 'https://' . $id . '.example'],
    'zones' => ['input' => 'zones', 'label' => 'Zones', 'required' => false, 'secret' => false, 'type' => 'textarea', 'default' => 'example.test'],
]];
$props = ['user' => new TowerDNS\Domain\Auth\User('viewer', 'viewer@example.test'), 'account' => $account, 'csrfToken' => 'fixture', 'canManageCredentials' => !$readonly, 'canListZones' => true, 'allowedTypes' => $readonly ? [] : ['first','second'], 'providerDefinitions' => $definitions, 'providers' => [new TowerDNS\Domain\Account\ProviderAccount(9, 42, 'first', 'Primary', 'secret-ciphertext-fixture', 3, true, '2026-01-01')]];
$translator = new Laminas\I18n\Translator\Translator();
$translator->setLocale(($_GET['locale'] ?? '') === 'de-DE' ? 'de-DE' : 'en-GB');
$translator->addTranslationFilePattern('phpArray', $root . '/translations', '%s.php');
header('Cache-Control: no-store');
echo (new TowerDNS\Infrastructure\Ui\SvelteRenderer(new TowerDNS\Application\Theme\ThemeManager($root), translator: $translator))->render('app::provider_accounts/list', $props);
