<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\CredentialService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Provider\DnsProviderFactory;

/**
 * Provider-Account management for an account (tenant).
 *
 * Routes handled (all behind RequireAuthMiddleware):
 *   GET  /accounts/{id}/providers               → list provider accounts
 *   POST /accounts/{id}/providers               → add a new provider account
 *   POST /accounts/{id}/providers/{pid}/replace → replace credentials
 *   POST /accounts/{id}/providers/{pid}/deactivate → deactivate provider account
 */
final readonly class ProviderAccountHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface          $renderer,
        private AccountRepositoryInterface         $accounts,
        private ProviderAccountRepositoryInterface $providerAccounts,
        private PermissionService                  $permissions,
        private CredentialService                  $credentials,
        private AuditLogService                    $audit,
        private DnsProviderFactory                 $providerFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path      = $request->getUri()->getPath();
        $method    = $request->getMethod();
        $accountId = (int) $request->getAttribute('id', 0);
        $pid       = $request->getAttribute('pid');

        if ($pid !== null) {
            if (str_ends_with($path, '/deactivate')) {
                return $this->handleDeactivate($request, $accountId, (int) $pid);
            }
            if (str_ends_with($path, '/replace')) {
                return $this->handleReplace($request, $accountId, (int) $pid);
            }
        }

        return $method === 'POST'
            ? $this->handleCreate($request, $accountId)
            : $this->handleList($request, $accountId);
    }

    // ── GET /accounts/{id}/providers ─────────────────────────────────────────

    private function handleList(ServerRequestInterface $request, int $accountId): ResponseInterface
    {
        /** @var User $user */
        $user    = $request->getAttribute(User::class);
        $account = $this->accounts->findById($accountId);

        if (!$account instanceof \TowerDNS\Domain\Account\Account) {
            return new HtmlResponse('Account nicht gefunden.', 404);
        }

        try {
            $this->permissions->assertCanManageProviderAccounts($accountId, $user);
        } catch (AuthorizationException) {
            return new HtmlResponse('Kein Zugriff.', 403);
        }

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        $providers  = $this->providerAccounts->findByAccountId($accountId);
        $flashError = $request->getQueryParams()['error'] ?? null;

        return new HtmlResponse(
            $this->renderer->render('app::provider_accounts/list', [
                'user'         => $user,
                'account'      => $account,
                'providers'    => $providers,
                'allowedTypes' => $this->providerFactory->userManagedTypes(),
                'csrfToken'    => $csrfToken,
                'error'        => is_string($flashError) ? $flashError : null,
            ]),
        );
    }

    // ── POST /accounts/{id}/providers ────────────────────────────────────────

    private function handleCreate(ServerRequestInterface $request, int $accountId): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        try {
            $this->permissions->assertCanManageProviderAccounts($accountId, $user);
        } catch (AuthorizationException) {
            return new HtmlResponse('Kein Zugriff.', 403);
        }

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse('Ungültige Anfrage.', 400);
        }

        $providerType = trim((string) ($body['provider_type'] ?? ''));
        $name         = trim((string) ($body['name'] ?? ''));
        $credJson     = $this->buildCredentialsJson($providerType, $body);
        $base         = '/accounts/' . $accountId . '/providers';

        if (!in_array($providerType, $this->providerFactory->userManagedTypes(), strict: true)) {
            return new RedirectResponse($base . '?error=' . rawurlencode('Unbekannter Provider-Typ.'));
        }

        if ($name === '') {
            return new RedirectResponse($base . '?error=' . rawurlencode('Name ist erforderlich.'));
        }

        if ($credJson === null) {
            return new RedirectResponse($base . '?error=' . rawurlencode('Credentials unvollständig.'));
        }

        try {
            $encrypted = $this->credentials->encrypt($credJson);
            $this->credentials->wipe($credJson);

            $paId = $this->providerAccounts->create(
                accountId: $accountId,
                providerType: $providerType,
                name: $name,
                credentialsEncrypted: $encrypted,
                credentialsVersion: 3,
                createdAt: new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            );

            $this->audit->recordProviderAccountCreated($request, $user->id, $accountId, $paId, $name, $providerType);
        } catch (\Throwable $e) {
            return new RedirectResponse($base . '?error=' . rawurlencode($e->getMessage()));
        }

        return new RedirectResponse($base);
    }

    // ── POST /accounts/{id}/providers/{pid}/replace ───────────────────────────

    private function handleReplace(ServerRequestInterface $request, int $accountId, int $paId): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);
        $base = '/accounts/' . $accountId . '/providers';

        try {
            $this->permissions->assertCanManageProviderAccounts($accountId, $user);
        } catch (AuthorizationException) {
            return new HtmlResponse('Kein Zugriff.', 403);
        }

        $pa = $this->providerAccounts->findById($paId);
        if (!$pa instanceof \TowerDNS\Domain\Account\ProviderAccount || $pa->accountId !== $accountId) {
            return new HtmlResponse('Provider-Account nicht gefunden.', 404);
        }

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse('Ungültige Anfrage.', 400);
        }

        $credJson = $this->buildCredentialsJson($pa->providerType, $body);

        if ($credJson === null) {
            return new RedirectResponse($base . '?error=' . rawurlencode('Credentials unvollständig.'));
        }

        try {
            $encrypted = $this->credentials->encrypt($credJson);
            $this->credentials->wipe($credJson);

            $this->providerAccounts->replaceCredentials($paId, $accountId, $encrypted, 3);
            $this->audit->recordProviderAccountCredentialsReplaced($request, $user->id, $accountId, $paId);
        } catch (\Throwable $e) {
            return new RedirectResponse($base . '?error=' . rawurlencode($e->getMessage()));
        }

        return new RedirectResponse($base);
    }

    // ── POST /accounts/{id}/providers/{pid}/deactivate ────────────────────────

    private function handleDeactivate(ServerRequestInterface $request, int $accountId, int $paId): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);
        $base = '/accounts/' . $accountId . '/providers';

        try {
            $this->permissions->assertCanManageProviderAccounts($accountId, $user);
        } catch (AuthorizationException) {
            return new HtmlResponse('Kein Zugriff.', 403);
        }

        $pa = $this->providerAccounts->findById($paId);
        if (!$pa instanceof \TowerDNS\Domain\Account\ProviderAccount || $pa->accountId !== $accountId) {
            return new HtmlResponse('Provider-Account nicht gefunden.', 404);
        }

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse('Ungültige Anfrage.', 400);
        }

        $this->providerAccounts->deactivate($paId, $accountId);
        $this->audit->recordProviderAccountDeactivated($request, $user->id, $accountId, $paId);

        return new RedirectResponse($base);
    }

    // ── Credential JSON builder ────────────────────────────────────────────────

    /**
     * Builds a JSON string for credentials based on provider type.
     * Returns null if required fields are missing.
     *
     * @param array<string, string> $body
     */
    private function buildCredentialsJson(string $providerType, array $body): ?string
    {
        $creds = $this->providerFactory->credentialsFromInput($providerType, $body);

        if ($creds === null || !$this->providerFactory->credentialsComplete($providerType, $creds)) {
            return null;
        }

        return json_encode($creds, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
