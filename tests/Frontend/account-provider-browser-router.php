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
if ($path !== '/fixture') {
    http_response_code(404);
    return;
}
require $root . '/vendor/autoload.php';
$readonly    = isset($_GET['readonly']);
$page        = (string) ($_GET['page'] ?? 'provider_accounts/list');
$inactive    = isset($_GET['inactive']);
$account     = new TowerDNS\Domain\Account\Account(42, 'Example Account', 'example', 'owner', !$inactive, '2026-01-01');
$definitions = [];
foreach (['first', 'second'] as $id) {
    $definitions[$id] = ['label' => ucfirst($id), 'user_managed' => true, 'credentials' => [
        'token' => ['input' => 'token', 'label' => 'Token', 'required' => true, 'secret' => true],
        'url'   => ['input' => 'url', 'label' => 'URL', 'required' => true, 'secret' => false, 'default' => 'https://' . $id . '.example'],
        'zones' => ['input' => 'zones', 'label' => 'Zones', 'required' => false, 'secret' => false, 'type' => 'textarea', 'default' => 'example.test'],
    ]];
}
$props   = ['user' => new TowerDNS\Domain\Auth\User('viewer', 'viewer@example.test'), 'account' => $account, 'csrfToken' => 'fixture', 'canManageCredentials' => !$readonly, 'canListZones' => true, 'allowedTypes' => $readonly ? [] : ['first','second'], 'providerDefinitions' => $definitions, 'providers' => [new TowerDNS\Domain\Account\ProviderAccount(9, 42, 'first', 'Primary', 'secret-ciphertext-fixture', 3, true, '2026-01-01'), new TowerDNS\Domain\Account\ProviderAccount(10, 42, 'first', 'Disabled', 'disabled-ciphertext-fixture', 3, false, '2026-01-01')]];
$actions = [
    'canViewAccount'   => true, 'canEditAccount' => !$readonly             && !$inactive,
    'canDeleteAccount' => false, 'canReactivateAccount' => !$readonly    && $inactive,
    'canManageMembers' => !$readonly                                     && !$inactive, 'canTransferOwnership' => false,
    'canViewProviders' => !$inactive, 'canManageProviders' => !$readonly && !$inactive,
    'canListZones'     => !$inactive, 'canCreateZones' => !$readonly         && !$inactive,
];
if ($page === 'accounts/edit' || $page === 'accounts/members') {
    $props += $actions + ['usage' => $inactive ? null : ['usedZones' => 0, 'usedProviderAccounts' => 0, 'usedMembers' => 1, 'maxZones' => null, 'maxProviderAccounts' => null, 'maxMembers' => null], 'members' => [], 'invitations' => [], 'roles' => []];
    // The provider fixture already supplies overlapping flags.
    $props = array_replace($props, $actions);
} elseif ($page === 'accounts/list') {
    $props += ['activeAccount' => $account, 'canListActiveZones' => true, 'activeAccountId' => 42, 'accounts' => [
        ['id' => 42, 'name' => 'Example Account', 'slug' => 'example', 'kind' => 'organization', 'isActive' => true, ...$actions],
        ['id' => 43, 'name' => 'Inactive Account', 'slug' => 'inactive', 'kind' => 'organization', 'isActive' => false, ...array_fill_keys(array_keys($actions), false)],
    ], 'roles' => [], 'page' => 1, 'hasNext' => false];
}
$translator = new Laminas\I18n\Translator\Translator();
$translator->setLocale(($_GET['locale'] ?? '') === 'de-DE' ? 'de-DE' : 'en-GB');
$translator->addTranslationFilePattern('phpArray', $root . '/translations', '%s.php');
header('Cache-Control: no-store');
echo new TowerDNS\Infrastructure\Ui\SvelteRenderer(new TowerDNS\Application\Theme\ThemeManager($root), translator: $translator)->render('app::' . $page, $props);
