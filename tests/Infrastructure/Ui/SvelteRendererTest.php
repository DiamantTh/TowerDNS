<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Ui;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Theme\ThemeManager;
use TowerDNS\Domain\Account\ProviderAccount;
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
        self::assertStringContainsString('provider_accounts', $html);
        self::assertStringNotContainsString('secret-ciphertext', $html);
        self::assertStringNotContainsString('serialized-public-key', $html);
    }
}
