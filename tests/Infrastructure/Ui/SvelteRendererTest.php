<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Ui;

use Laminas\I18n\Translator\Translator;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Auth\ActionGroupDefinition;
use TowerDNS\Application\Theme\ThemeManager;
use TowerDNS\Domain\Account\ProviderAccount;
use TowerDNS\Domain\Auth\PermissionDefinition;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Ui\SvelteRenderer;

final class SvelteRendererTest extends TestCase
{
    public function testRendererBootstrapsSvelteWithoutLeakingStoredCredentials(): void
    {
        $root     = dirname(__DIR__, 3);
        $renderer = new SvelteRenderer(new ThemeManager($root));
        $provider = new ProviderAccount(1, 2, 'desec', 'Production', 'secret-ciphertext', 1, true, '2026-01-01');

        $html = $renderer->render('app::provider_accounts/list', [
            'providers' => [$provider],
            'keys'      => [['name' => 'Passkey', 'source' => 'serialized-public-key']],
        ]);

        self::assertStringContainsString('id="towerdns-app"', $html);
        self::assertStringContainsString('<title>TowerDNS</title>', $html);
        self::assertStringContainsString('provider_accounts', $html);
        self::assertStringNotContainsString('secret-ciphertext', $html);
        self::assertStringNotContainsString('serialized-public-key', $html);
    }

    public function testRendererExposesBuiltInRoleMarkerWithoutCallingItSystemScope(): void
    {
        $renderer = new SvelteRenderer(new ThemeManager(dirname(__DIR__, 3)));
        $html     = $renderer->render('app::iam/roles', [
            'roles' => [new Role('superadmin', 'Super Administrator', isBuiltIn: true)],
        ]);

        self::assertStringContainsString('"isBuiltIn":true', $html);
        self::assertStringNotContainsString('"isSystem"', $html);
    }

    public function testRendererProvidesRoleEditorMetadataForSvelte(): void
    {
        $renderer = new SvelteRenderer(new ThemeManager(dirname(__DIR__, 3)));
        $html     = $renderer->render('app::iam/roles', [
            'permissionDefinitions' => [new PermissionDefinition('towerdns.tlsa.manage', 'permission.tlsa.manage.label')],
            'actionGroups'          => [new ActionGroupDefinition('dns.records.manage', 'action-group.dns.records.manage.label', null, ['towerdns.tlsa.manage'])],
        ]);

        self::assertStringContainsString('permissionDefinitions', $html);
        self::assertStringContainsString('towerdns.tlsa.manage', $html);
        self::assertStringContainsString('actionGroups', $html);
        self::assertStringContainsString('dns.records.manage', $html);
    }

    public function testRendererOnlyExposesPersonalContactFieldsOnTheOwnProfilePage(): void
    {
        $renderer = new SvelteRenderer(new ThemeManager(dirname(__DIR__, 3)));
        $user     = new User(
            id: 'user-1',
            email: 'person@example.test',
            roles: [new Role('dns-reader', 'DNS Reader', ['zone.records.read'])],
            displayName: 'Example Person',
            phone: '+1-555-0100',
            street: '123 Private Street',
            externalReference: 'private-crm-reference',
            lastLoginAt: '2026-09-20T12:00:00+00:00',
            createdAt: '2026-01-01T00:00:00+00:00',
        );

        $dashboard = $renderer->render('app::dashboard', ['user' => $user]);
        self::assertStringContainsString('Example Person', $dashboard);
        self::assertStringContainsString('person@example.test', $dashboard);
        self::assertStringContainsString('zone.records.read', $dashboard);
        self::assertStringNotContainsString('+1-555-0100', $dashboard);
        self::assertStringNotContainsString('123 Private Street', $dashboard);
        self::assertStringNotContainsString('private-crm-reference', $dashboard);

        $userList = $renderer->render('app::iam/users', ['users' => [$user]]);
        self::assertStringContainsString('"active":true', $userList);
        self::assertStringNotContainsString('+1-555-0100', $userList);
        self::assertStringNotContainsString('zone.records.read', $userList);

        $profile = $renderer->render('app::profile/index', ['user' => $user]);
        self::assertStringContainsString('+1-555-0100', $profile);
        self::assertStringContainsString('123 Private Street', $profile);
        self::assertStringNotContainsString('private-crm-reference', $profile);
    }

    public function testRendererExposesTheLaminasTranslationCatalogToSvelte(): void
    {
        $root       = dirname(__DIR__, 3);
        $translator = new Translator();
        $translator->setLocale('de-DE');
        $translator->setFallbackLocale('en-GB');
        $translator->addTranslationFilePattern('phpArray', $root . '/translations', '%s.php');
        $renderer = new SvelteRenderer(new ThemeManager($root), translator: $translator);

        $html = $renderer->render('app::iam/roles');

        self::assertStringContainsString('"locale":"de-DE"', $html);
        self::assertStringContainsString('DNS-Eintr\u00e4ge verwalten', $html);
        self::assertSame('Rolle „DNS Manager“ wurde angelegt.', $this->interpolate($translator->translate('roles.success.created'), ['name' => 'DNS Manager']));
        self::assertSame('2 Rollen', $this->interpolate($translator->translatePlural('roles.count', 'roles.count', 2), ['count' => '2']));
        self::assertSame('Save', $translator->translate('common.save', 'default', 'fr-FR'));
    }

    /** @param array<string, string> $parameters */
    private function interpolate(string $text, array $parameters): string
    {
        $replacements = [];
        foreach ($parameters as $name => $value) {
            $replacements['{' . $name . '}'] = $value;
        }

        return strtr($text, $replacements);
    }
}
