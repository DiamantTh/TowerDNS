<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Devium\Toml\Toml;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Provider\DnsProviderFactory;

/**
 * GET+POST /credentials — provider credential management.
 *
 * Reads and writes configs/providers.toml. The handler never touches keys
 * it does not know about (i.e. arbitrary extra fields written by the
 * installer or a future version are preserved).
 *
 * Each provider section is updated independently via a hidden `provider`
 * field in the submitted form. Empty strings for required fields clear the
 * corresponding provider section entirely (effectively disabling it).
 */
final readonly class ProviderCredentialsHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private AuthorizationService $authz,
        private string $credentialsPath,
        private DnsProviderFactory $providerFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        try {
            $this->authz->assert($user, Permission::PROVIDER_CREDENTIALS_MANAGE);
        } catch (AuthorizationException) {
            return new HtmlResponse('Keine Berechtigung.', 403);
        }

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        if ($request->getMethod() === 'POST') {
            return $this->handlePost($request, $guard);
        }

        return $this->renderForm($request, $user, $guard->generateToken());
    }

    private function handlePost(ServerRequestInterface $request, CsrfGuardInterface $guard): ResponseInterface
    {
        /** @var array<string, string> $body */
        $body      = (array) ($request->getParsedBody() ?? []);
        $csrfToken = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($csrfToken)) {
            return new HtmlResponse('Ungültige Anfrage.', 400);
        }

        $provider = (string) ($body['provider'] ?? '');

        // Load current state
        $config = $this->loadConfig();

        $credentials = $this->providerFactory->credentialsFromInput($provider, $body);
        if ($credentials === null) {
            return new RedirectResponse('/credentials?error=' . rawurlencode('Unbekannter Provider.'));
        }
        $stored     = (array) ($config['providers'][$provider] ?? []);
        $definition = $this->providerFactory->definitions()[$provider];
        foreach ($definition['credentials'] as $key => $field) {
            if ($field['secret'] && $credentials[$key] === '' && isset($stored[$key])) {
                $credentials[$key] = (string) $stored[$key];
            }
        }
        if ($this->providerFactory->credentialsComplete($provider, $credentials)) {
            $config['providers'][$provider] = $credentials;
        } else {
            return new RedirectResponse('/credentials?error=' . rawurlencode('Credentials unvollständig.'));
        }

        try {
            $this->saveConfig($config);
        } catch (\Throwable $e) {
            return new RedirectResponse(
                '/credentials?error=' . rawurlencode('Fehler beim Speichern: ' . $e->getMessage())
            );
        }

        return new RedirectResponse('/credentials?success=' . rawurlencode('Zugangsdaten gespeichert.'));
    }

    private function renderForm(ServerRequestInterface $request, User $user, string $csrfToken): ResponseInterface
    {
        $query   = $request->getQueryParams();
        $error   = isset($query['error']) ? (string) $query['error'] : null;
        $success = isset($query['success']) ? (string) $query['success'] : null;

        $config    = $this->loadConfig();
        $providers = (array) ($config['providers'] ?? []);

        // Expose only non-sensitive metadata (no plain-text secrets in template)
        $configured = [];
        $values     = [];
        foreach ($this->providerFactory->definitions() as $id => $definition) {
            $stored          = (array) ($providers[$id] ?? []);
            $configured[$id] = $this->providerFactory->credentialsComplete($id, $stored);
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
            'providerDefinitions' => $this->providerFactory->definitions(),
            'error'               => $error,
            'success'             => $success,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        if (!is_file($this->credentialsPath)) {
            return ['providers' => []];
        }

        $raw = file_get_contents($this->credentialsPath);
        if ($raw === false) {
            return ['providers' => []];
        }

        /** @var array<string, mixed> $data */
        $data = (array) Toml::decode($raw, asArray: true);
        return $data;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function saveConfig(array $config): void
    {
        $content = "# TowerDNS — Provider-Konfiguration\n";
        $content .= "# NIEMALS ins Git einpflegen!\n\n";
        $content .= Toml::encode($config);

        if (file_put_contents($this->credentialsPath, $content) === false) {
            throw new \RuntimeException('providers.toml konnte nicht geschrieben werden: ' . $this->credentialsPath);
        }
    }
}
