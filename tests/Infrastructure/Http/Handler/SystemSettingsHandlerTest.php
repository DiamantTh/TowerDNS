<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Laminas\Diactoros\ServerRequest;
use Laminas\I18n\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Repository\SystemSettingsRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Theme\ThemeManager;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Configuration\AtomicConfigurationWriter;
use TowerDNS\Infrastructure\Http\Handler\SystemSettingsHandler;

final class SystemSettingsHandlerTest extends TestCase
{
    public function testGetDoesNotExposeConfiguredMailerDsnAndProvidesRuntimeSecuritySettings(): void
    {
        $configPath = tempnam(sys_get_temp_dir(), 'towerdns-settings-');
        self::assertNotFalse($configPath);
        file_put_contents($configPath, <<<TOML
            [mailer]
            dsn = "smtp://operator:secret@example.test"
            from_address = "dns@example.test"
            TOML);

        try {
            $renderer = $this->createMock(TemplateRendererInterface::class);
            $renderer->expects(self::once())->method('render')->with('app::settings', self::callback(
                static function (array $data): bool {
                    $fields = $data['fields'];

                    return $fields['mailer_dsn']        === ''
                        && $fields['mailer_configured'] === true
                        && $fields['hibp_enabled']      === true
                        && $fields['hibp_fail_open']    === false
                        && $fields['hibp_timeout']      === 4.5;
                },
            ))->willReturn('<html></html>');
            $translator = $this->createMock(TranslatorInterface::class);
            $guard      = $this->createMock(CsrfGuardInterface::class);
            $guard->method('generateToken')->willReturn('csrf');
            $settings = $this->createMock(SystemSettingsRepositoryInterface::class);
            $settings->method('get')->willReturnMap([
                ['security.password.min_length', 16, 18],
                ['security.password.min_score', 2, 3],
                ['security.password.hibp_enabled', false, true],
                ['security.password.hibp_fail_open', true, false],
                ['security.password.hibp_timeout', 3.0, 4.5],
            ]);
            $handler = new SystemSettingsHandler(
                $renderer,
                new AuthorizationService(),
                $settings,
                new ThemeManager(dirname(__DIR__, 4)),
                $configPath,
                new AtomicConfigurationWriter(),
                $translator,
            );
            $user    = new User('admin', 'admin@example.test', [new Role('settings', 'Settings', [Permission::SYSTEM_SETTINGS_MANAGE])]);
            $request = new ServerRequest()
                ->withAttribute(User::class, $user)
                ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard);

            self::assertSame(200, $handler->handle($request)->getStatusCode());
        } finally {
            @unlink($configPath);
        }
    }

    public function testPostPersistsHibpPolicyAndKeepsRedactedMailerDsn(): void
    {
        $configPath = tempnam(sys_get_temp_dir(), 'towerdns-settings-');
        self::assertNotFalse($configPath);
        file_put_contents($configPath, <<<TOML
            [mailer]
            dsn = "smtp://operator:secret@example.test"
            from_address = "dns@example.test"
            TOML);

        try {
            $renderer   = $this->createMock(TemplateRendererInterface::class);
            $translator = $this->createMock(TranslatorInterface::class);
            $guard      = $this->createMock(CsrfGuardInterface::class);
            $guard->method('generateToken')->willReturn('csrf');
            $guard->method('validateToken')->with('csrf')->willReturn(true);
            $settings = $this->createMock(SystemSettingsRepositoryInterface::class);
            $settings->expects(self::once())->method('setMany')->with(
                [
                    'security.password.min_length'     => 20,
                    'security.password.min_score'      => 4,
                    'security.password.hibp_enabled'   => true,
                    'security.password.hibp_fail_open' => false,
                    'security.password.hibp_timeout'   => 5.0,
                ],
                'admin',
            );
            $handler = new SystemSettingsHandler(
                $renderer,
                new AuthorizationService(),
                $settings,
                new ThemeManager(dirname(__DIR__, 4)),
                $configPath,
                new AtomicConfigurationWriter(),
                $translator,
            );
            $user    = new User('admin', 'admin@example.test', [new Role('settings', 'Settings', [Permission::SYSTEM_SETTINGS_MANAGE])]);
            $request = new ServerRequest()
                ->withMethod('POST')
                ->withParsedBody([
                    'csrf_token'          => 'csrf',
                    'app_name'            => 'TowerDNS',
                    'app_hostname'        => 'tower.example.test',
                    'theme_name'          => 'default',
                    'pwd_min_length'      => '20',
                    'pwd_min_score'       => '4',
                    'hibp_enabled'        => '1',
                    'hibp_timeout'        => '5',
                    'mailer_dsn'          => '',
                    'mailer_from_address' => 'dns@example.test',
                ])
                ->withAttribute(User::class, $user)
                ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard);

            $response = $handler->handle($request);

            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/settings?success=saved', $response->getHeaderLine('Location'));
            self::assertStringContainsString('smtp://operator:secret@example.test', (string) file_get_contents($configPath));
        } finally {
            @unlink($configPath);
        }
    }

    public function testSavedFlashIsTranslatedOnGet(): void
    {
        $renderer = $this->createMock(TemplateRendererInterface::class);
        $renderer->expects(self::once())->method('render')->with('app::settings', self::callback(
            static fn(array $data): bool => $data['success'] === 'Einstellungen wurden gespeichert.'
        ))->willReturn('<html></html>');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->with('settings.success.saved')->willReturn('Einstellungen wurden gespeichert.');
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->method('generateToken')->willReturn('csrf');
        $settings = $this->createMock(SystemSettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(static fn(string $key, mixed $default): mixed => $default);

        $handler = new SystemSettingsHandler(
            $renderer,
            new AuthorizationService(),
            $settings,
            new ThemeManager(dirname(__DIR__, 4)),
            '/tmp/towerdns-nonexistent-settings-test.toml',
            new AtomicConfigurationWriter(),
            $translator,
        );
        $user    = new User('admin', 'admin@example.test', [new Role('settings', 'Settings', [Permission::SYSTEM_SETTINGS_MANAGE])]);
        $request = new ServerRequest()->withQueryParams(['success' => 'saved'])
            ->withAttribute(User::class, $user)
            ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard);

        self::assertSame(200, $handler->handle($request)->getStatusCode());
    }
}
