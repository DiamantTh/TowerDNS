<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\I18n\Translator\TranslatorInterface;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Repository\ApiKeyRepositoryInterface;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Handler\ApiKeyHandler;

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
}
