<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\Provider\ProviderRegistry;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;
use TowerDNS\Domain\DNS\Zone;
use TowerDNS\Infrastructure\Console\RecordListCommand;

final class RecordListCommandTest extends TestCase
{
    public function testResolvesZoneNameBeforeListingRecords(): void
    {
        $provider = $this->createMock(DnsProviderInterface::class);
        $provider->method('id')->willReturn('fake');
        $provider->method('listZones')->willReturn([
            new Zone('provider-zone-42', 'example.org', 'fake', true),
        ]);
        $provider->expects(self::once())
            ->method('listRecords')
            ->with('provider-zone-42')
            ->willReturn([
                new Record('record-1', 'provider-zone-42', 'www.example.org', RecordType::A, 300, '192.0.2.1'),
            ]);

        $tester = new CommandTester(new RecordListCommand(new ProviderRegistry([$provider])));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'provider' => 'fake',
            'zone'     => 'EXAMPLE.ORG.',
        ]));
        self::assertStringContainsString('www.example.org', $tester->getDisplay());
    }

    public function testReportsMissingZoneName(): void
    {
        $provider = $this->createMock(DnsProviderInterface::class);
        $provider->method('id')->willReturn('fake');
        $provider->method('listZones')->willReturn([]);
        $provider->expects(self::never())->method('listRecords');

        $tester = new CommandTester(new RecordListCommand(new ProviderRegistry([$provider])));

        self::assertSame(Command::FAILURE, $tester->execute([
            'provider' => 'fake',
            'zone'     => 'missing.example',
        ]));
        self::assertStringContainsString('was not found', $tester->getDisplay());
    }
}
