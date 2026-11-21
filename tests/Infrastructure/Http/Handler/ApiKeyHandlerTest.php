<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Repository\ApiKeyRepositoryInterface;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Handler\ApiKeyHandler;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class ApiKeyHandlerTest extends TestCase
{
    public function testPostWithoutAKeyIdDoesNotIssueAnUnsupportedApiCredential(): void
    {
        $keys = $this->createMock(ApiKeyRepositoryInterface::class);
        $keys->expects(self::never())->method('findByUserId');
        $keys->expects(self::never())->method('revoke');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->willReturn('Not found.');

        $handler = new ApiKeyHandler(
            $this->createMock(TemplateRendererInterface::class),
            $keys,
            $translator,
        );
        $request = new ServerRequest()
            ->withMethod('POST')
            ->withParsedBody(['name' => 'unsupported token', 'csrf_token' => 'synthetic'])
            ->withAttribute(User::class, new User('user-1', 'user@example.test'));

        $response = $handler->handle($request);

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not found.', (string) $response->getBody());
    }

    public function testUpcomingExpiryIsShownAsRotationWarning(): void
    {
        $keys = $this->createMock(ApiKeyRepositoryInterface::class);
        $keys->method('findByUserId')->willReturn([[
            'id'            => 4, 'name' => 'ACME hook', 'created_at' => null, 'last_used' => null, 'is_active' => true,
            'scope'         => 'tlsa:append', 'expires_at' => new \DateTimeImmutable('+15 days', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'scope_account' => 1, 'scope_zone' => 2, 'scope_owner' => '_443._tcp',
        ]]);
        $renderer = $this->createMock(TemplateRendererInterface::class);
        $renderer->expects(self::once())->method('render')->willReturnCallback(static function (string $template, array $data): string {
            self::assertSame('api-key.status.expiring', $data['keys'][0]['statusLabel']);
            return 'rendered';
        });
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->willReturnArgument(0);
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->method('generateToken')->willReturn('csrf');

        $handler = new ApiKeyHandler($renderer, $keys, $translator);
        $request = new ServerRequest()->withUri(new Uri('https://tower.example.test/profile/api-keys'))
            ->withAttribute(User::class, new User('user-1', 'user@example.test'))
            ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard);

        self::assertSame('rendered', (string) $handler->handle($request)->getBody());
    }
}
