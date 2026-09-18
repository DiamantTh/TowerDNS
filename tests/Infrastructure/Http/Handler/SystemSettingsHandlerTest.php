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
