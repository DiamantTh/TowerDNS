<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Laminas\Diactoros\ServerRequest;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\Session;
use Mezzio\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Services\ActiveAccountService;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Handler\ActiveAccountHandler;

/** @psalm-api */
final class ActiveAccountHandlerTest extends TestCase
{
    public function testSelectionOpensZoneWorkspaceInsteadOfManagementOnlyPage(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findByUserId')->willReturn([new Account(42, 'Example', 'example', 'someone-else', true, '2026-01-01')]);
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->method('validateToken')->with('valid')->willReturn(true);
        $session = new Session([]);
        $request = new ServerRequest()->withParsedBody(['csrf_token' => 'valid', 'account_id' => '42'])
            ->withAttribute(User::class, new User('viewer', 'viewer@example.test'))
            ->withAttribute(SessionInterface::class, $session)->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard);
        $response = new ActiveAccountHandler(new ActiveAccountService($accounts))->handle($request);
        self::assertSame('/accounts/42/zones', $response->getHeaderLine('Location'));
        self::assertSame(42, $session->get('active_account_id'));
    }
}
