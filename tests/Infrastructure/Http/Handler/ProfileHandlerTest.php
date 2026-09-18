<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Laminas\Diactoros\ServerRequest;
use Laminas\I18n\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\CredentialEncryptorInterface;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\ProfileService;
use TowerDNS\Application\Services\TotpSecretService;
use TowerDNS\Application\Services\TotpService;
use TowerDNS\Application\Theme\ThemeManager;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Clock\SystemClock;
use TowerDNS\Infrastructure\Http\Handler\ProfileHandler;

final class ProfileHandlerTest extends TestCase
{
    public function testSuccessMessageIsTranslatedOnTheRequestAfterLocaleSwitch(): void
    {
        $renderer = $this->createMock(TemplateRendererInterface::class);
        $renderer->expects(self::once())->method('render')->with('app::profile/index', self::callback(
            static fn(array $data): bool => $data['success'] === 'Profil aktualisiert.'
        ))->willReturn('<html></html>');
        $users    = $this->createMock(UserRepositoryInterface::class);
        $webauthn = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $webauthn->method('findByUserId')->willReturn([]);
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findByUserId')->willReturn([]);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->with('profile.success.updated')->willReturn('Profil aktualisiert.');
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->method('generateToken')->willReturn('csrf');

        $handler = new ProfileHandler(
            $renderer,
            $webauthn,
            new TotpSecretService($users, new TotpService(new SystemClock()), $this->createMock(CredentialEncryptorInterface::class)),
            new ProfileService($users, new ThemeManager(dirname(__DIR__, 4))),
            $accounts,
            new AuditLogService($this->createMock(AuditLogRepositoryInterface::class)),
            $translator,
        );
        $request = new ServerRequest()->withQueryParams(['success' => 'updated'])
            ->withAttribute('actor_user', new User('user-1', 'user@example.test', locale: 'de-DE'))
            ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard);

        self::assertSame(200, $handler->handle($request)->getStatusCode());
    }
}
