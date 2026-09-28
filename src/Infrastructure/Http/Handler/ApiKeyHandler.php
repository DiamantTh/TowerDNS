<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\I18n\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\ApiKeyRepositoryInterface;
use TowerDNS\Domain\Auth\User;

/**
 * Lists and revokes legacy API-key records for the authenticated user.
 *
 * Incoming TowerDNS API authentication is not implemented, so new keys are
 * deliberately not issued. Existing records remain revocable.
 *
 * GET  /profile/api-keys             – list existing records
 * POST /profile/api-keys/{id}/revoke – revoke a specific key
 */
final readonly class ApiKeyHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private ApiKeyRepositoryInterface $apiKeys,
        private TranslatorInterface       $translator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        $matched = $request->getAttribute(\Mezzio\Router\RouteResult::class)?->getMatchedParams() ?? [];
        $keyId   = isset($matched['id']) ? (int) $matched['id'] : null;

        if ($request->getMethod() === 'POST') {
            if ($keyId === null) {
                return new HtmlResponse($this->translator->translate('http.error.not-found'), 404);
            }

            /** @var array<string, mixed> $body */
            $body      = (array) ($request->getParsedBody() ?? []);
            $rawToken  = $body['csrf_token'] ?? '';
            $csrfToken = is_array($rawToken) ? (string) ($rawToken[0] ?? '') : (string) $rawToken;

            if (!$guard->validateToken($csrfToken)) {
                return new RedirectResponse('/profile/api-keys?error=' . rawurlencode($this->translator->translate('http.error.invalid-request')));
            }

            $this->apiKeys->revoke($keyId, $currentUser->id);

            return new RedirectResponse('/profile/api-keys?success=' . rawurlencode($this->translator->translate('api-key.success.revoked')));
        }

        return $this->renderList($request, $currentUser, $guard);
    }

    private function renderList(
        ServerRequestInterface $request,
        User                   $currentUser,
        CsrfGuardInterface     $guard,
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $keys   = array_map(function (array $key): array {
            $key['statusLabel'] = $this->translator->translate($key['is_active'] ? 'api-key.status.not-accepted' : 'api-key.status.revoked');

            return $key;
        }, $this->apiKeys->findByUserId($currentUser->id));

        return new HtmlResponse(
            $this->renderer->render('app::profile/api_keys', [
                'user'      => $currentUser,
                'csrfToken' => $guard->generateToken(),
                'active'    => 'profile',
                'keys'      => $keys,
                'error'     => isset($params['error']) ? (string) $params['error'] : null,
                'success'   => isset($params['success']) ? (string) $params['success'] : null,
            ])
        );
    }
}
