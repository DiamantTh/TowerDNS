<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\ServerRequest;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Handler\AuditLogHandler;
use TowerDNS\Infrastructure\Persistence\DbalAuditLogRepository;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

final class AuditLogHandlerTest extends TestCase
{
    public function testAccountAuditIsScopedPaginatedAndOmitsRawPayloads(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new SchemaManager($db)->createTablesIfNotExist();
        foreach (range(1, 52) as $id) {
            $db->insert('audit_logs', [
                'id'                       => $id,
                'account_id'               => $id === 52 ? 2 : 1,
                'action'                   => 'record.update',
                'target_type'              => 'record',
                'actor_user_id'            => 'operator',
                'effective_user_id'        => 'effective',
                'impersonation_session_id' => 'switch-id',
                'metadata_json'            => '{broken secret',
                'created_at'               => '2026-01-01 00:00:00',
            ]);
        }

        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findById')->willReturn(new Account(1, 'Example', 'example', 'owner', true, '2026-01-01'));
        $accounts->method('getEffectiveRole')->willReturnMap([[1, 'viewer', TeamRole::AUDITOR]]);
        $permissions = new PermissionService($accounts, $this->createMock(ZoneMembershipRepositoryInterface::class));
        $repository  = new DbalAuditLogRepository($db);
        $renderer    = $this->createMock(TemplateRendererInterface::class);
        $renderer->expects(self::once())->method('render')->with('app::audit/index', self::callback(static function (array $payload): bool {
            $event = $payload['events'][0];
            return count($payload['events'])   === 50 && $payload['hasMore']
                                                      && (int) $event['id']          === 51 && $event['actor_user_id'] === 'operator'
                                                      && $event['effective_user_id'] === 'effective' && $event['impersonation_session_id'] === 'switch-id'
                                                      && !array_key_exists('metadata_json', $event);
        }))->willReturn('<html></html>');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('translate')->willReturnArgument(0);
        $handler = new AuditLogHandler($renderer, $translator, $permissions, $repository);
        $request = new ServerRequest()->withAttribute(User::class, new User('viewer', 'viewer@example.test'))
            ->withAttribute('account', '1')->withQueryParams(['action' => 'record.update']);

        self::assertSame(200, $handler->handle($request)->getStatusCode());
        self::assertSame(403, $handler->handle($request->withAttribute('account', '2'))->getStatusCode());
        self::assertSame(403, $handler->handle($request->withAttribute('zone', '2'))->getStatusCode());
        self::assertSame([], $repository->pageForAccount(1, null, ['action' => 'other'], 0));
        self::assertCount(1, $repository->pageForAccount(1, null, [], 50));
    }
}
