<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use TowerDNS\Application\Contracts\DnssecDiagnosticInterface;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\MonitoringService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\ManagedZone;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\ApiKeyGrant;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Clock\SystemClock;
use TowerDNS\Infrastructure\Http\Handler\ZoneMonitoringHandler;
use TowerDNS\Infrastructure\Persistence\DbalMonitoringRepository;
use TowerDNS\Infrastructure\Persistence\MonitoringAlerts;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

/** @psalm-api Discovered by PHPUnit. */
final class ZoneMonitoringHandlerTest extends TestCase
{
    public function testReadRequiresDedicatedScopeAndCurrentZoneAccess(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new SchemaManager($db)->createTablesIfNotExist();
        $at = gmdate('Y-m-d H:i:s');
        $db->insert('managed_zones', ['id' => 7, 'account_id' => 42, 'provider_account_id' => 1, 'provider_zone_id' => 'fixture', 'canonical_name' => 'example.test', 'created_at' => $at]);
        $accounts = $this->createStub(AccountRepositoryInterface::class);
        $accounts->method('findById')->willReturn(new Account(42, 'Fixture', 'fixture', 'user', true, $at));
        $accounts->method('getEffectiveRole')->willReturnCallback(static fn(int $account, string $user): ?TeamRole => $account !== 42 ? null : match ($user) {
            'member' => TeamRole::OWNER, 'reader' => TeamRole::VIEWER, default => null,
        });
        $zones = $this->createStub(ManagedZoneRepositoryInterface::class);
        $zones->method('findByIdForAccount')->willReturn(new ManagedZone(7, 42, 1, 'fixture', 'example.test', $at));
        $handler = new ZoneMonitoringHandler(
            new PermissionService($accounts, $this->createStub(ZoneMembershipRepositoryInterface::class), managedZones: $zones),
            new MonitoringAlerts($db),
            $this->createStub(TemplateRendererInterface::class),
            new MonitoringService(new DbalMonitoringRepository($db), $this->createStub(DnssecDiagnosticInterface::class), new SystemClock()),
            new AuditLogService($this->createStub(AuditLogRepositoryInterface::class)),
            new Psr16Cache(new ArrayAdapter()),
        );
        $request = new ServerRequest()->withUri(new Uri('https://example.test/api/v1/accounts/42/zones/7/monitoring'))
            ->withAttribute('account', '42')->withAttribute('zone', '7')->withAttribute(User::class, new User('member', 'member@example.test'));
        $valid = $request->withAttribute(ApiKeyGrant::class, new ApiKeyGrant(1, 'member', 'monitoring:read', 42, 7, null));

        self::assertSame(403, $handler->handle($request)->getStatusCode());
        self::assertSame(403, $handler->handle($request->withAttribute(ApiKeyGrant::class, new ApiKeyGrant(2, 'member', 'tlsa:read', 42, 7, null)))->getStatusCode());
        self::assertSame(403, $handler->handle($valid->withAttribute('zone', '8'))->getStatusCode());
        self::assertSame(403, $handler->handle($valid->withAttribute('account', '43'))->getStatusCode());
        self::assertSame(403, $handler->handle($valid->withAttribute(User::class, new User('former-member', 'former@example.test')))->getStatusCode());
        self::assertSame(422, $handler->handle($valid->withQueryParams(['days' => '181']))->getStatusCode());
        $response = $handler->handle($valid);
        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('"dane_handshake":"not_tested"', $body);
        self::assertStringNotContainsString('recipient', $body);

        $web   = $request->withUri(new Uri('https://example.test/accounts/42/zones/7/monitoring'))->withMethod('POST');
        $guard = $this->createStub(\Mezzio\Csrf\CsrfGuardInterface::class);
        $guard->method('validateToken')->willReturnCallback(static fn(string $token): bool => $token === 'valid-fixture');
        $web  = $web->withAttribute(\Mezzio\Csrf\CsrfMiddleware::GUARD_ATTRIBUTE, $guard);
        $body = ['csrf_token' => 'valid-fixture', 'interval_minutes' => '15', 'enabled' => '1', 'smtp_dane_enabled' => '1', 'recipient' => 'forged@example.test'];
        self::assertSame(403, $handler->handle($web->withParsedBody(array_replace($body, ['csrf_token' => 'invalid'])))->getStatusCode());
        self::assertSame(403, $handler->handle($web->withParsedBody($body)->withAttribute(User::class, new User('reader', 'reader@example.test')))->getStatusCode());
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM dns_monitoring_config'));
        self::assertSame(303, $handler->handle($web->withParsedBody($body))->getStatusCode());
        self::assertSame(15, (int) $db->fetchOne('SELECT interval_minutes FROM dns_monitoring_config'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM dns_monitoring_alerts'));

        new MonitoringAlerts($db)->settings(7, 'protected@example.test', 'en-GB', 'warning', 2, 0, false);
        $repository = new DbalMonitoringRepository($db);
        foreach ([gmdate('Y-m-d H:i:s', time() - 600), $at] as $observedAt) {
            $repository->recordResult(7, 'smtp_dane', 'dnssec_bogus', 'critical', 'fixture', [], hash('sha256', 'bogus'), $observedAt, '2026-01-01 00:00:00');
        }
        $result = (string) $handler->handle($valid)->getBody();
        self::assertStringContainsString('"smtp_dane_enabled":true', $result);
        self::assertStringContainsString('"kind":"dnssec_bogus"', $result);
        self::assertStringNotContainsString('protected@example.test', $result);
    }
}
