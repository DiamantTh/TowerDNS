<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\I18n\Translator\TranslatorInterface;
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
 */
final readonly class ZoneListHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private ManagedZoneDNSService     $dns,
        private TranslatorInterface       $translator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user      = $request->getAttribute(User::class);
        $accountId = (int) $request->getAttribute('account', 0);
        if ($accountId <= 0) {
            $context   = $request->getAttribute(ActiveAccountContext::class);
            $accountId = $context instanceof ActiveAccountContext && $context->account instanceof \TowerDNS\Domain\Account\Account ? $context->account->id : 0;
        }

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        try {
            $zones            = $this->dns->list($user, $accountId);
            $providerAccounts = $this->dns->availableProviderAccounts($user, $accountId);
        } catch (AuthorizationException) {
            return new HtmlResponse(
                $this->renderer->render('app::zones/list', [
                    'user'             => $user,
                    'accountId'        => $accountId,
                    'managedZones'     => [],
                    'providerAccounts' => [],
                    'csrfToken'        => $csrfToken,
                    'error'            => $this->translator->translate('http.error.forbidden'),
                ]),
                403,
            );
        }

        $flashError = $request->getQueryParams()['error'] ?? null;

        return new HtmlResponse(
            $this->renderer->render('app::zones/list', [
                'user'             => $user,
                'accountId'        => $accountId,
                'managedZones'     => $zones,
                'providerAccounts' => $providerAccounts,
                'csrfToken'        => $csrfToken,
                'error'            => is_string($flashError) ? $flashError : null,
            ]),
        );
    }
}
