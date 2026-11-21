<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\ApiKeyRepositoryInterface;
use TowerDNS\Domain\Auth\User;

/**
 * Lists and revokes API keys belonging to the authenticated user.
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
        private ?\TowerDNS\Application\Services\AuditLogService $audit = null,
    ) {}

    #[\Override]
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

            if ($csrfToken === '' || !$guard->validateToken($csrfToken)) {
                return new RedirectResponse('/profile/api-keys?error=' . rawurlencode($this->translator->translate('http.error.invalid-request')));
            }

            if ($this->apiKeys->revoke($keyId, $currentUser->id)) {
                $this->audit?->record($request, 'api_key.revoke', 'api_key', (string) $keyId, $currentUser->id);
            }

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
            $now       = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $expiry    = $key['expires_at'] ?? null;
            $expiresAt = is_string($expiry)
                ? new \DateTimeImmutable($expiry, new \DateTimeZone('UTC'))
                : null;
            $key['statusLabel'] = $this->translator->translate(match (true) {
                !$key['is_active']                                                                 => 'api-key.status.revoked',
                ($key['scope'] ?? null) === null                                                   => 'api-key.status.not-accepted',
                $expiresAt instanceof \DateTimeImmutable && $expiresAt <= $now                     => 'api-key.status.expired',
                $expiresAt instanceof \DateTimeImmutable && $expiresAt <= $now->modify('+30 days') => 'api-key.status.expiring',
                default                                                                            => 'api-key.status.active',
            });

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
