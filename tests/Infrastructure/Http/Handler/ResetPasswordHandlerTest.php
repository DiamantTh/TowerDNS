<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Repository\PasswordResetTokenRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\NullBreachedPasswordChecker;
use TowerDNS\Application\Services\PasswordPolicy;
use TowerDNS\Application\Services\PasswordResetService;
use TowerDNS\Domain\Account\AuditLogEntry;
use TowerDNS\Domain\Auth\PasswordResetToken;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Handler\ResetPasswordHandler;
use TowerDNS\Infrastructure\Persistence\DbalPasswordResetTokenRepository;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class ResetPasswordHandlerTest extends TestCase
{
    public function testSuccessfulResetConsumesAndAuditsTicketRedemptionAndCompletion(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id VARCHAR(64) NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE, created_at VARCHAR(19) NOT NULL, expires_at VARCHAR(19) NOT NULL, used_at VARCHAR(19) DEFAULT NULL, method VARCHAR(32) NOT NULL DEFAULT \'email_link\')');
        $tokens   = new DbalPasswordResetTokenRepository($connection);
        $rawToken = 'single-use-reset-ticket';
        $tokens->create('user-1', hash('sha256', $rawToken), '2026-09-16 12:00:00');

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with('user-1')->willReturn(new User('user-1', 'user@example.test'));
        $users->expects(self::once())->method('updatePasswordHash')->with('user-1', self::callback('is_string'));

        $clock = new class implements ClockInterface {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-09-15 12:00:00');
            }
        };
        $resets = new PasswordResetService(
            $connection,
            $tokens,
            $users,
            new PasswordPolicy(8, 0, new NullBreachedPasswordChecker()),
            $clock,
        );
        $entries         = [];
        $auditRepository = $this->createMock(AuditLogRepositoryInterface::class);
        $auditRepository->expects(self::exactly(2))->method('append')->willReturnCallback(
            static function (AuditLogEntry $entry, string $createdAt) use (&$entries): void {
                self::assertNotSame('', $createdAt);
                $entries[] = $entry;
            },
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->willReturnArgument(0);
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->method('validateToken')->with('csrf')->willReturn(true);
        $guard->method('generateToken')->willReturn('csrf');
        $renderer = $this->createMock(TemplateRendererInterface::class);
        $renderer->method('render')->willReturn('error');

        $handler = new ResetPasswordHandler(
            $renderer,
            $tokens,
            $resets,
            new AuditLogService($auditRepository),
            $translator,
            $clock,
            new Psr16Cache(new ArrayAdapter()),
        );
        $response = $handler->handle(
            new ServerRequest()->withMethod('POST')->withParsedBody([
                'csrf_token'       => 'csrf',
                'reset_token'      => $rawToken,
                'password'         => 'correct horse battery staple',
                'password_confirm' => 'correct horse battery staple',
            ])->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard),
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/login?reset=1', $response->getHeaderLine('Location'));
        self::assertSame([
            'security.password.recovery_ticket.redeemed',
            'user.password.reset',
        ], array_map(static fn(AuditLogEntry $entry): string => $entry->action, $entries));
        foreach ($entries as $entry) {
            self::assertSame('user-1', $entry->targetId);
            self::assertStringNotContainsString($rawToken, serialize($entry));
            self::assertStringNotContainsString(hash('sha256', $rawToken), serialize($entry));
        }
        $consumed = $tokens->findByHash(hash('sha256', $rawToken));
        self::assertNotNull($consumed);
        self::assertTrue($consumed->isUsed());
    }

    public function testExpiredTicketIsRejectedAndExpiryIsAuditedWithoutLoggingTheToken(): void
    {
        $rawToken = 'known-secret-recovery-ticket';
        $token    = new PasswordResetToken(
            17,
            'user-1',
            hash('sha256', $rawToken),
            '2026-09-14 12:00:00',
            '2026-09-15 11:59:59',
        );
        $tokens = $this->createMock(PasswordResetTokenRepositoryInterface::class);
        $tokens->expects(self::exactly(2))->method('findByHash')->with(hash('sha256', $rawToken))->willReturn($token);

        $auditRepository = $this->createMock(AuditLogRepositoryInterface::class);
        $auditRepository->expects(self::once())->method('append')->with(
            self::callback(static fn(AuditLogEntry $entry): bool => $entry->action === 'security.password.recovery_ticket.expired'
                && $entry->targetId                                                === 'user-1'
                && $entry->metadataJson                                            === ['purpose' => 'email_password_reset']
                && $entry->beforeJson                                              === null
                && $entry->afterJson                                               === null),
            self::callback('is_string'),
        );

        $clock = new class implements ClockInterface {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-09-15 12:00:00');
            }
        };
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $resets     = new PasswordResetService(
            $connection,
            $tokens,
            $this->createMock(UserRepositoryInterface::class),
            new PasswordPolicy(8, 0, new NullBreachedPasswordChecker()),
            $clock,
        );
        $renderer = $this->createMock(TemplateRendererInterface::class);
        $renderer->method('render')->willReturn('expired');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->willReturnArgument(0);
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->method('generateToken')->willReturn('csrf');

        $handler = new ResetPasswordHandler(
            $renderer,
            $tokens,
            $resets,
            new AuditLogService($auditRepository),
            $translator,
            $clock,
            new Psr16Cache(new ArrayAdapter()),
        );

        $response = $handler->handle(
            new ServerRequest()
                ->withMethod('GET')
                ->withQueryParams(['token' => $rawToken])
                ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard),
        );

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
    }
}
