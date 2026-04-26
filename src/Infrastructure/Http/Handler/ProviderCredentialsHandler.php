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
final class ProviderCredentialsHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly AuthorizationService $authz,
        private readonly string $credentialsPath,
    ) {
    }

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

        return $this->renderForm($request, $guard->generateToken());
    }

    private function handlePost(ServerRequestInterface $request, CsrfGuardInterface $guard): ResponseInterface
    {
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse('Ungültige Anfrage.', 400);
        }

        $provider = (string) ($body['provider'] ?? '');

        // Load current state
        $config = $this->loadConfig();

        switch ($provider) {
            case 'desec':
                $token = trim((string) ($body['desec_token'] ?? ''));
                if ($token !== '') {
                    $config['providers']['desec'] = ['token' => $token];
                } else {
                    unset($config['providers']['desec']);
                }
                break;

            case 'powerdns':
                $baseUrl  = trim((string) ($body['powerdns_base_url'] ?? ''));
                $apiKey   = trim((string) ($body['powerdns_api_key'] ?? ''));
                $serverId = trim((string) ($body['powerdns_server_id'] ?? 'localhost'));
                if ($baseUrl !== '' && $apiKey !== '') {
                    $config['providers']['powerdns'] = [
                        'base_url'  => $baseUrl,
                        'api_key'   => $apiKey,
                        'server_id' => $serverId !== '' ? $serverId : 'localhost',
                    ];
                } else {
                    unset($config['providers']['powerdns']);
                }
                break;

            case 'cloudflare':
                $apiToken = trim((string) ($body['cloudflare_api_token'] ?? ''));
                if ($apiToken !== '') {
                    $config['providers']['cloudflare'] = ['api_token' => $apiToken];
                } else {
                    unset($config['providers']['cloudflare']);
                }
                break;

            case 'inwx':
                $username = trim((string) ($body['inwx_username'] ?? ''));
                $password = trim((string) ($body['inwx_password'] ?? ''));
                if ($username !== '' && $password !== '') {
                    $config['providers']['inwx'] = [
                        'username' => $username,
                        'password' => $password,
                    ];
                } else {
                    unset($config['providers']['inwx']);
                }
                break;

            default:
                return new RedirectResponse('/credentials?error=' . rawurlencode('Unbekannter Provider.'));
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

    private function renderForm(ServerRequestInterface $request, string $csrfToken): ResponseInterface
    {
        $query   = $request->getQueryParams();
        $error   = isset($query['error'])   ? (string) $query['error']   : null;
        $success = isset($query['success']) ? (string) $query['success'] : null;

        $config    = $this->loadConfig();
        $providers = (array) ($config['providers'] ?? []);

        // Expose only non-sensitive metadata (no plain-text secrets in template)
        $configured = [
            'desec'      => isset($providers['desec']['token']),
            'powerdns'   => isset($providers['powerdns']['base_url']),
            'cloudflare' => isset($providers['cloudflare']['api_token']),
            'inwx'       => isset($providers['inwx']['username']),
        ];

        $values = [
            'desec_token'          => '',
            'powerdns_base_url'    => (string) ($providers['powerdns']['base_url'] ?? ''),
            'powerdns_server_id'   => (string) ($providers['powerdns']['server_id'] ?? 'localhost'),
            'cloudflare_api_token' => '',
            'inwx_username'        => (string) ($providers['inwx']['username'] ?? ''),
        ];

        return new HtmlResponse($this->renderer->render('app::credentials', [
            'active'      => 'credentials',
            'csrfToken'   => $csrfToken,
            'configured'  => $configured,
            'values'      => $values,
            'error'       => $error,
            'success'     => $success,
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
