<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

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
use TowerDNS\Application\Services\MailService;
use TowerDNS\Domain\Account\AuditLogEntry;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Handler\ForgotPasswordHandler;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class ForgotPasswordHandlerTest extends TestCase
{
    public function testIssuedRecoveryTicketExpiresAfterTwentyFourHoursAndAuditsWithoutSecrets(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('findByEmail')->with('user@example.test')->willReturn(new User('user-1', 'user@example.test'));

        $tokens = $this->createMock(PasswordResetTokenRepositoryInterface::class);
        $tokens->expects(self::once())->method('create')->with(
            'user-1',
            self::callback(static fn(string $tokenHash): bool => preg_match('/^[a-f0-9]{64}$/D', $tokenHash) === 1),
            '2026-09-16 12:00:00',
        );

        $auditRepository = $this->createMock(AuditLogRepositoryInterface::class);
        $auditRepository->expects(self::once())->method('append')->with(
            self::callback(static fn(AuditLogEntry $entry): bool => $entry->action === 'security.password.recovery_ticket.created'
                && $entry->targetId                                                === 'user-1'
                && $entry->metadataJson                                            === [
                    'purpose'    => 'email_password_reset',
                    'expires_at' => '2026-09-16 12:00:00',
                ]
                && $entry->beforeJson === null
                && $entry->afterJson  === null),
            self::callback('is_string'),
        );

        $csrf = $this->createMock(CsrfGuardInterface::class);
        $csrf->method('validateToken')->with('csrf')->willReturn(true);
        $csrf->method('generateToken')->willReturn('csrf');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->willReturnArgument(0);
        $clock = new class implements ClockInterface {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-09-15 12:00:00');
            }
        };

        $handler = new ForgotPasswordHandler(
            $this->createMock(TemplateRendererInterface::class),
            $users,
            $tokens,
            new MailService('null://null', 'noreply@example.test', 'TowerDNS'),
            'https://tower.example.test',
            $translator,
            new Psr16Cache(new ArrayAdapter()),
            $clock,
            new AuditLogService($auditRepository),
        );

        $request = new ServerRequest()
            ->withMethod('POST')
            ->withParsedBody(['csrf_token' => 'csrf', 'email' => 'user@example.test'])
            ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $csrf);

        $response = $handler->handle($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/password/forgot?sent=1', $response->getHeaderLine('Location'));
    }
}
