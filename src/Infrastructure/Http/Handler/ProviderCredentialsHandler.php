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
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\ProviderConfigurationException;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\SystemProviderConfigurationService;
use TowerDNS\Domain\Auth\User;

/**
 * GET+POST /credentials — provider credential management.
 *
 * Configures deliberately system-wide providers. Tenant-owned credentials
 * belong to ProviderAccountHandler instead.
 *
 * Each provider section is updated independently via a hidden `provider`
 * field in the submitted form. Empty strings for required fields clear the
 * corresponding provider section entirely (effectively disabling it).
 */
final readonly class ProviderCredentialsHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private SystemProviderConfigurationService $providers,
        private AuditLogService $audit,
        private TranslatorInterface $translator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        try {
            $configuration = $this->providers->current($user);
        } catch (AuthorizationException) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        if ($request->getMethod() === 'POST') {
            return $this->handlePost($request, $guard);
        }

        return $this->renderForm($request, $user, $guard->generateToken(), $configuration);
    }

    private function handlePost(ServerRequestInterface $request, CsrfGuardInterface $guard): ResponseInterface
    {
        /** @var array<string, string> $body */
        $body      = (array) ($request->getParsedBody() ?? []);
        $csrfToken = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($csrfToken)) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }

        $provider = (string) ($body['provider'] ?? '');

        try {
            /** @var User $user */
            $user = $request->getAttribute(User::class);
            $this->providers->update($user, $provider, $body);
            $this->audit->recordSystemProviderConfigurationUpdated($request, $user->id, $provider);
        } catch (ProviderConfigurationException $exception) {
            $key = $exception->reason === ProviderConfigurationException::UNKNOWN_PROVIDER
                ? 'providers.error.unknown-type'
                : 'providers.error.credentials-incomplete';
            return new RedirectResponse('/credentials?error=' . rawurlencode($this->translator->translate($key)));
        } catch (AuthorizationException) {
            return new RedirectResponse('/credentials?error=' . rawurlencode($this->translator->translate('http.error.forbidden')));
        } catch (\Throwable) {
            return new RedirectResponse('/credentials?error=' . rawurlencode($this->translator->translate('providers.error.system-config-save-failed')));
        }

        return new RedirectResponse('/credentials?success=' . rawurlencode($this->translator->translate('providers.success.system-config-saved')));
    }

    /** @param array<string, mixed> $configuration */
    private function renderForm(ServerRequestInterface $request, User $user, string $csrfToken, array $configuration): ResponseInterface
    {
        $query   = $request->getQueryParams();
        $error   = isset($query['error']) ? (string) $query['error'] : null;
        $success = isset($query['success']) ? (string) $query['success'] : null;

        $providers = (array) ($configuration['providers'] ?? []);

        // Expose only non-sensitive metadata (no plain-text secrets in template)
        $configured = [];
        $values     = [];
        foreach ($this->providers->definitions() as $id => $definition) {
            $stored          = (array) ($providers[$id] ?? []);
            $configured[$id] = $this->providers->credentialsComplete($id, $stored);
            foreach ($definition['credentials'] as $key => $field) {
                $values[$field['input']] = $field['secret']
                    ? ''
                    : (string) ($stored[$key] ?? $field['default'] ?? '');
            }
        }

        return new HtmlResponse($this->renderer->render('app::credentials', [
            'user'                => $user,
            'active'              => 'credentials',
            'csrfToken'           => $csrfToken,
            'configured'          => $configured,
            'values'              => $values,
            'providerDefinitions' => $this->providers->definitions(),
            'error'               => $error,
            'success'             => $success,
        ]));
    }
}
