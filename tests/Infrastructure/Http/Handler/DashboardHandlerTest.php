<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Laminas\Diactoros\ServerRequest;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\ActiveAccountContext;
use TowerDNS\Infrastructure\Http\Handler\DashboardHandler;

final class DashboardHandlerTest extends TestCase
{
    public function testOnlyAuthorizedAccountContextQueriesAndRendersLocalCounts(): void
    {
        $account  = new Account(42, 'Team', 'team', 'owner', true, '2026-01-01');
        $accounts = $this->createStub(AccountRepositoryInterface::class);
        $accounts->method('findById')->willReturn($account);
        $accounts->method('getEffectiveRole')->willReturnMap([[42, 'viewer', TeamRole::VIEWER]]);
        $zones = $this->createMock(ManagedZoneRepositoryInterface::class);
        $zones->expects(self::once())->method('countByAccountId')->with(42)->willReturn(0);
        $providers = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providers->expects(self::once())->method('countByAccountId')->with(42)->willReturn(2);
        $credentials = $this->createStub(WebAuthnCredentialRepositoryInterface::class);
        $guard       = $this->createStub(CsrfGuardInterface::class);
        $guard->method('generateToken')->willReturn('csrf');
        $renderer = $this->createMock(TemplateRendererInterface::class);
        $renderer->expects(self::exactly(3))->method('render')->willReturnCallback(static function (string $page, array $data): string {
            self::assertSame('app::dashboard', $page);
            $authorized = $data['account'] !== null && $data['user']->id === 'viewer';
            self::assertSame($authorized ? 0 : null, $data['zoneCount']);
            self::assertSame($authorized ? 2 : null, $data['providerCount']);
            return '<html></html>';
        });
        $permissions = new PermissionService($accounts, $this->createStub(ZoneMembershipRepositoryInterface::class));
        $handler     = new DashboardHandler($renderer, $permissions, $zones, $providers, $credentials);
        $request     = new ServerRequest()->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard)
            ->withAttribute(User::class, new User('viewer', 'viewer@example.test'));

        self::assertSame(200, $handler->handle($request)->getStatusCode());
        $withAccount = $request->withAttribute(ActiveAccountContext::class, new ActiveAccountContext($account));
        self::assertSame(200, $handler->handle($withAccount)->getStatusCode());
        self::assertSame(200, $handler->handle($withAccount->withAttribute(User::class, new User('outsider', 'outsider@example.test')))->getStatusCode());
    }
}
