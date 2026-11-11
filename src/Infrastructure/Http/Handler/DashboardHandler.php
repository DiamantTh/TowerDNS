<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\ActiveAccountContext;

/**
 * GET / — renders the main dashboard.
 * @psalm-api Constructed through runtime dependency injection or command/handler registration.
 */
final readonly class DashboardHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private PermissionService $permissions,
        private ManagedZoneRepositoryInterface $zones,
        private ProviderAccountRepositoryInterface $providers,
        private WebAuthnCredentialRepositoryInterface $credentials,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User|null $user */
        $user = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        $context   = $request->getAttribute(ActiveAccountContext::class);
        $account   = $context instanceof ActiveAccountContext ? $context->account : null;
        $personal  = $user instanceof User       && $user->active && $request->getAttribute('impersonation_session') === null;
        $zoneCount = $account instanceof Account && $user instanceof User && $this->permissions->authorizeAccount($user, Permission::ZONE_LIST, $account->id)
            ? $this->zones->countByAccountId($account->id) : null;
        $providerCount = $account instanceof Account && $user instanceof User && $this->permissions->authorizeAccount($user, Permission::ACCOUNT_READ, $account->id)
            ? $this->providers->countByAccountId($account->id) : null;
        return new HtmlResponse(
            $this->renderer->render('app::dashboard', [
                'user'            => $user,
                'account'         => $account,
                'zoneCount'       => $zoneCount,
                'providerCount'   => $providerCount,
                'showInvitations' => $personal,
                'offerPasskey'    => $personal && $user instanceof User && $this->credentials->countByUserId($user->id) === 0,
                'csrfToken'       => $guard->generateToken(),
            ])
        );
    }
}
