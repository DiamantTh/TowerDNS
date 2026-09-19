<?php

declare(strict_types=1);

namespace TowerDNSTestsInfrastructureInstallation;

use PHPUnit\Framework\TestCase;

final class InstallerLocaleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('INSTALL_DIR')) {
            define('INSTALL_DIR', dirname(__DIR__, 3) . '/install');
        }
        require_once dirname(__DIR__, 3) . '/install/inc/i18n.php';
    }

    protected function tearDown(): void
    {
        unset($_GET['lang'], $_SESSION['installer_lang'], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    public function testMissingAcceptLanguageHeaderFallsBackToEnglish(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = '';

        self::assertSame('en-GB', \detectInstallerLocale());
    }

    public function testAcceptLanguagePrefixSelectsSupportedLocale(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de;q=0.9,en;q=0.8';

        self::assertSame('de-DE', \detectInstallerLocale());
    }
}
