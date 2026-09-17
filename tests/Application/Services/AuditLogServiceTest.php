<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use Laminas\Diactoros\ServerRequest;
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

    /**
     * fromHttpRequest() must prefer the 'client_ip' request attribute (as
     * resolved by the Infrastructure-layer ClientIpMiddleware, honoring any
     * configured trusted proxy) over raw REMOTE_ADDR — without this service
     * importing anything from the Infrastructure namespace.
     */
    public function testFromHttpRequestPrefersResolvedClientIpAttributeOverRemoteAddr(): void
    {
        $request = new ServerRequest(serverParams: ['REMOTE_ADDR' => '10.0.0.1'])
            ->withAttribute('client_ip', '198.51.100.7');

        $context = AuditLogService::fromHttpRequest($request, 'actor');

        self::assertSame('198.51.100.7', $context->ipAddress);
    }

    public function testFromHttpRequestFallsBackToRemoteAddrWhenAttributeMissing(): void
    {
        $request = new ServerRequest(serverParams: ['REMOTE_ADDR' => '203.0.113.5']);

        $context = AuditLogService::fromHttpRequest($request, 'actor');

        self::assertSame('203.0.113.5', $context->ipAddress);
    }
}
