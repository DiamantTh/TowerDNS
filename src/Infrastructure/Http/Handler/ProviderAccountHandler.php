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
use TowerDNS\Application\DTO\ProviderAccountListing;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\ProviderAccountException;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\ProviderAccountManagementService;
use TowerDNS\Domain\Auth\User;

/** HTTP transport adapter for tenant provider-account operations. */
final readonly class ProviderAccountHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private ProviderAccountManagementService $providers,
        private AuditLogService $audit,
        private TranslatorInterface $translator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path              = $request->getUri()->getPath();
        $accountId         = (int) $request->getAttribute('id', 0);
        $providerAccountId = $request->getAttribute('pid');

        if ($providerAccountId !== null && str_ends_with($path, '/deactivate')) {
            return $this->deactivate($request, $accountId, (int) $providerAccountId);
        }
        if ($providerAccountId !== null && str_ends_with($path, '/replace')) {
            return $this->replace($request, $accountId, (int) $providerAccountId);
        }

        return $request->getMethod() === 'POST'
            ? $this->create($request, $accountId)
            : $this->list($request, $accountId);
    }

    private function list(ServerRequestInterface $request, int $accountId): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        try {
            $listing = $this->providers->list($user, $accountId);
        } catch (AuthorizationException) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        } catch (ProviderAccountException $exception) {
            return new HtmlResponse($this->translator->translate($this->errorKey($exception)), 404);
        }

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        return $this->renderList($request, $user, $listing, $guard->generateToken());
    }

    private function create(ServerRequestInterface $request, int $accountId): ResponseInterface
    {
        if (!($guard = $this->csrfGuard($request)) instanceof CsrfGuardInterface || !$guard->validateToken($this->csrfToken($request))) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }

        /** @var User $user */
        $user = $request->getAttribute(User::class);
        /** @var array<string, mixed> $input */
        $input = (array) ($request->getParsedBody() ?? []);
        $base  = '/accounts/' . $accountId . '/providers';

        try {
            $result = $this->providers->create($user, $accountId, trim((string) ($input['provider_type'] ?? '')), trim((string) ($input['name'] ?? '')), $input);
            $this->audit->recordProviderAccountCreated($request, $user->id, $result->accountId, $result->providerAccountId, $result->name ?? '', $result->providerType);
        } catch (AuthorizationException) {
            return $this->redirectError($base, 'http.error.forbidden');
        } catch (ProviderAccountException $exception) {
            return $this->redirectError($base, $this->errorKey($exception));
        } catch (\Throwable) {
            return $this->redirectError($base, 'providers.error.account-save-failed');
        }

        return $this->redirectSuccess($base, 'providers.success.account-created');
    }

    private function replace(ServerRequestInterface $request, int $accountId, int $providerAccountId): ResponseInterface
    {
        if (!($guard = $this->csrfGuard($request)) instanceof CsrfGuardInterface || !$guard->validateToken($this->csrfToken($request))) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }

        /** @var User $user */
        $user = $request->getAttribute(User::class);
        /** @var array<string, mixed> $input */
        $input = (array) ($request->getParsedBody() ?? []);
        $base  = '/accounts/' . $accountId . '/providers';

        try {
            $result = $this->providers->replaceCredentials($user, $accountId, $providerAccountId, $input);
            $this->audit->recordProviderAccountCredentialsReplaced($request, $user->id, $result->accountId, $result->providerAccountId);
        } catch (AuthorizationException) {
            return $this->redirectError($base, 'http.error.forbidden');
        } catch (ProviderAccountException $exception) {
            return $this->redirectError($base, $this->errorKey($exception));
        } catch (\Throwable) {
            return $this->redirectError($base, 'providers.error.account-save-failed');
        }

        return $this->redirectSuccess($base, 'providers.success.credentials-replaced');
    }

    private function deactivate(ServerRequestInterface $request, int $accountId, int $providerAccountId): ResponseInterface
    {
        if (!($guard = $this->csrfGuard($request)) instanceof CsrfGuardInterface || !$guard->validateToken($this->csrfToken($request))) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }

        /** @var User $user */
        $user = $request->getAttribute(User::class);
        $base = '/accounts/' . $accountId . '/providers';

        try {
            $result = $this->providers->deactivate($user, $accountId, $providerAccountId);
            $this->audit->recordProviderAccountDeactivated($request, $user->id, $result->accountId, $result->providerAccountId);
        } catch (AuthorizationException) {
            return $this->redirectError($base, 'http.error.forbidden');
        } catch (ProviderAccountException $exception) {
            return $this->redirectError($base, $this->errorKey($exception));
        } catch (\Throwable) {
            return $this->redirectError($base, 'providers.error.account-save-failed');
        }

        return $this->redirectSuccess($base, 'providers.success.account-deactivated');
    }

    private function csrfGuard(ServerRequestInterface $request): ?CsrfGuardInterface
    {
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        return $guard instanceof CsrfGuardInterface ? $guard : null;
    }

    private function csrfToken(ServerRequestInterface $request): string
    {
        $body = (array) ($request->getParsedBody() ?? []);
        return is_string($body['csrf_token'] ?? null) ? $body['csrf_token'] : '';
    }

    private function renderList(ServerRequestInterface $request, User $user, ProviderAccountListing $listing, string $csrfToken): ResponseInterface
    {
        $flashError   = $request->getQueryParams()['error']   ?? null;
        $flashSuccess = $request->getQueryParams()['success'] ?? null;

        return new HtmlResponse($this->renderer->render('app::provider_accounts/list', [
            'user'         => $user,
            'account'      => $listing->account,
            'providers'    => $listing->providerAccounts,
            'allowedTypes' => $listing->allowedTypes,
            'csrfToken'    => $csrfToken,
            'error'        => is_string($flashError) ? $flashError : null,
            'success'      => is_string($flashSuccess) ? $flashSuccess : null,
        ]));
    }

    private function redirectError(string $base, string $key): RedirectResponse
    {
        return new RedirectResponse($base . '?error=' . rawurlencode($this->translator->translate($key)));
    }

    private function redirectSuccess(string $base, string $key): RedirectResponse
    {
        return new RedirectResponse($base . '?success=' . rawurlencode($this->translator->translate($key)));
    }

    private function errorKey(ProviderAccountException $exception): string
    {
        return match ($exception->reason) {
            ProviderAccountException::ACCOUNT_NOT_FOUND         => 'accounts.error.not-found',
            ProviderAccountException::PROVIDER_NOT_FOUND        => 'providers.error.account-not-found',
            ProviderAccountException::PROVIDER_NOT_USER_MANAGED => 'providers.error.not-user-managed',
            ProviderAccountException::NAME_REQUIRED             => 'providers.error.name-required',
            default                                             => 'providers.error.credentials-incomplete',
        };
    }
}
