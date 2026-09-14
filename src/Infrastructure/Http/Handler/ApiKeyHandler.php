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
use Mezzio\Session\SessionInterface;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\ApiKeyRepositoryInterface;
use TowerDNS\Domain\Auth\User;

/**
 * Manages API keys for the currently authenticated user.
 *
 * GET  /profile/api-keys             – list keys + create form
 * POST /profile/api-keys             – generate a new key (token shown once)
 * POST /profile/api-keys/{id}/revoke – revoke a specific key
 *
 * Token format : tdns_<64 hex chars>  (32 random bytes, hex-encoded)
 * Storage      : SHA-256 hex digest only — plaintext is never persisted.
 */
final readonly class ApiKeyHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private ApiKeyRepositoryInterface $apiKeys,
        private ClockInterface            $clock,
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
            return $this->handlePost($request, $currentUser, $guard, $keyId);
        }

        return $this->renderList($request, $currentUser, $guard);
    }

    private function handlePost(
        ServerRequestInterface $request,
        User                   $currentUser,
        CsrfGuardInterface     $guard,
        ?int                   $keyId,
    ): ResponseInterface {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $rawToken  = $body['csrf_token'] ?? '';
        $csrfToken = is_array($rawToken) ? (string) ($rawToken[0] ?? '') : (string) $rawToken;

        if (!$guard->validateToken($csrfToken)) {
            return new RedirectResponse('/profile/api-keys?error=' . rawurlencode($this->translator->translate('http.error.invalid-request')));
        }

        // ── Revoke ────────────────────────────────────────────────────────────
        if ($keyId !== null) {
            $this->apiKeys->revoke($keyId, $currentUser->id);

            return new RedirectResponse('/profile/api-keys?success=' . rawurlencode($this->translator->translate('api-key.success.revoked')));
        }

        // ── Create ────────────────────────────────────────────────────────────
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return new RedirectResponse('/profile/api-keys?error=' . rawurlencode($this->translator->translate('api-key.error.name-required')));
        }
        if (mb_strlen($name) > 100) {
            return new RedirectResponse('/profile/api-keys?error=' . rawurlencode($this->translator->translate('api-key.error.name-too-long')));
        }

        $session = $request->getAttribute(SessionInterface::class);
        if (!$session instanceof SessionInterface) {
            return new RedirectResponse('/profile/api-keys?error=' . rawurlencode($this->translator->translate('auth.error.session-unavailable')));
        }

        $plainToken = 'tdns_' . bin2hex(random_bytes(32));
        $keyHash    = hash('sha256', $plainToken);
        $now        = $this->clock->now()->format('Y-m-d H:i:s');

        $this->apiKeys->create($currentUser->id, $name, $keyHash, $now);

        $session->set('api_key.one_time.' . $currentUser->id, $plainToken);

        return new RedirectResponse('/profile/api-keys?success=' . rawurlencode($this->translator->translate('api-key.success.created-copy-now')));
    }

    private function renderList(
        ServerRequestInterface $request,
        User                   $currentUser,
        CsrfGuardInterface     $guard,
    ): ResponseInterface {
        $params   = $request->getQueryParams();
        $keys     = $this->apiKeys->findByUserId($currentUser->id);
        $session  = $request->getAttribute(SessionInterface::class);
        $flashKey = 'api_key.one_time.' . $currentUser->id;
        $newToken = $session instanceof SessionInterface ? $session->get($flashKey) : null;
        if ($session instanceof SessionInterface) {
            $session->unset($flashKey);
        }
        $newToken = is_string($newToken) && preg_match('/^tdns_[0-9a-f]{64}$/', $newToken) ? $newToken : null;

        return new HtmlResponse(
            $this->renderer->render('app::profile/api_keys', [
                'user'      => $currentUser,
                'csrfToken' => $guard->generateToken(),
                'active'    => 'profile',
                'keys'      => $keys,
                'newToken'  => $newToken,
                'error'     => isset($params['error']) ? (string) $params['error'] : null,
                'success'   => isset($params['success']) ? (string) $params['success'] : null,
            ])
        );
    }
}
