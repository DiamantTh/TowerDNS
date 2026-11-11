<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Services\ManagedZoneDNSService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\ActiveAccountContext;

/**
 * GET /accounts/{account}/zones — lists locally managed zones for one account.
 * @psalm-api Constructed through runtime dependency injection or command/handler registration.
 */
final readonly class ZoneListHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private ManagedZoneDNSService     $dns,
        private TranslatorInterface       $translator,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(User::class);
        if (!$user instanceof User) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }
        $accountId = (int) $request->getAttribute('account', 0);
        if ($accountId <= 0) {
            $context = $request->getAttribute(ActiveAccountContext::class);
            if ($context instanceof ActiveAccountContext && $context->account instanceof \TowerDNS\Domain\Account\Account) {
                $accountId = $context->account->id;
            }
        }

        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard instanceof CsrfGuardInterface ? $guard->generateToken() : '';

        try {
            $zones   = $this->dns->list($user, $accountId);
            $actions = $this->dns->zoneActions($user, $accountId);
            try {
                $providerAccounts = $this->dns->availableProviderAccounts($user, $accountId);
            } catch (AuthorizationException) {
                $providerAccounts = [];
            }
            $providerNames = $this->dns->providerNames($user, $accountId);
        } catch (AuthorizationException) {
            return new HtmlResponse(
                $this->renderer->render('app::zones/list', [
                    'user'             => $user,
                    'accountId'        => $accountId,
                    'managedZones'     => [],
                    'providerAccounts' => [],
                    'providerNames'    => [],
                    'csrfToken'        => $csrfToken,
                    'error'            => $this->translator->translate('http.error.forbidden'),
                ]),
                403,
            );
        }

        $providerId = filter_var($request->getQueryParams()['provider'] ?? '', FILTER_VALIDATE_INT);
        $filter     = $providerId === false || $providerId === 0 ? '' : (string) $providerId;
        $filter     = isset($providerNames[(int) $filter]) ? $filter : '';
        $flashError = $request->getQueryParams()['error'] ?? null;

        return new HtmlResponse(
            $this->renderer->render('app::zones/list', [
                'user'             => $user,
                'accountId'        => $accountId,
                'managedZones'     => $zones,
                'providerFilter'   => $filter,
                'providerAccounts' => $providerAccounts,
                'providerNames'    => $providerNames,
                'csrfToken'        => $csrfToken,
                'error'            => is_string($flashError) ? $flashError : null,
                ...$actions,
            ]),
        );
    }
}
