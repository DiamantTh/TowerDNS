<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Security;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\AbstractLogger;
use TowerDNS\Infrastructure\Security\HibpRangePasswordChecker;

final class HibpRangePasswordCheckerTest extends TestCase
{
    public function testNetworkFailureDoesNotLogClientExceptionDetails(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willThrowException(
            new class ('upstream response exposed a credential') extends \RuntimeException implements ClientExceptionInterface {},
        );

        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level'   => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $checker = new HibpRangePasswordChecker($client, $requestFactory, true, $logger);

        self::assertSame(0, $checker->timesSeen('correct horse battery staple'));
        self::assertSame([
            [
                'level'   => 'warning',
                'message' => 'HIBP range lookup failed',
                'context' => [],
            ],
        ], $logger->records);
    }
}
