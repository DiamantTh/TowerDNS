<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\DTO\AuditContext;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Domain\Account\AuditLogEntry;

final class AuditLogServiceTest extends TestCase
{
    public function testRecordsAServiceOrCliMutationWithoutAnHttpRequest(): void
    {
        $repository = $this->createMock(AuditLogRepositoryInterface::class);
        $repository->expects(self::once())->method('append')->with(
            self::callback(static fn(AuditLogEntry $entry): bool => $entry->actorUserId === 'actor'
                && $entry->effectiveUserId                                              === 'effective'
                && $entry->accountId                                                    === 42
                && $entry->zoneId                                                       === '7'
                && $entry->action                                                       === 'zone.member.grant'
                && $entry->metadataJson                                                 === ['role' => 'viewer']
                && $entry->ipAddress                                                    === null),
            self::isType('string'),
        );

        new AuditLogService($repository)->recordWithContext(
            new AuditContext('actor', 'effective', 42, '7'),
            'zone.member.grant',
            'user',
            'target',
            metadata: ['role' => 'viewer'],
        );
    }
}
