<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Devium\Toml\Toml;
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
use TowerDNS\Application\Repository\SystemSettingsRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Theme\ThemeManager;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Configuration\AtomicConfigurationWriter;

/**
 * GET+POST /settings — Systemeinstellungen lesen und schreiben.
 *
 * Schreibt nur bekannte, sichere Felder; `[security].encryption_key`
 * und Datenbankzugangsdaten werden niemals überschrieben.
 */
final readonly class SystemSettingsHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface         $renderer,
        private AuthorizationService              $authz,
        private SystemSettingsRepositoryInterface $settings,
        private ThemeManager                      $themes,
        private string                            $configPath,
        private AtomicConfigurationWriter         $configWriter,
        private TranslatorInterface               $translator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        try {
            $this->authz->assert($user, Permission::SYSTEM_SETTINGS_MANAGE);
        } catch (AuthorizationException) {
            return new HtmlResponse(
                $this->renderer->render('app::settings', [
                    'user'      => $user,
                    'fields'    => [],
                    'error'     => $this->translator->translate('http.error.forbidden'),
                    'success'   => null,
                    'csrfToken' => '',
                ]),
                403,
            );
        }

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        if ($request->getMethod() === 'POST') {
            return $this->handlePost($request, $guard, $user, $csrfToken);
        }

        $queryParams  = $request->getQueryParams();
        $flashError   = isset($queryParams['error'])   && is_string($queryParams['error']) ? $queryParams['error'] : null;
        $flashSuccess = isset($queryParams['success']) && is_string($queryParams['success']) ? $queryParams['success'] : null;

        $fields = $this->readFields();

        return new HtmlResponse(
            $this->renderer->render('app::settings', [
                'user'      => $user,
                'fields'    => $fields,
                'error'     => $flashError,
                'success'   => $flashSuccess,
                'csrfToken' => $csrfToken,
            ]),
        );
    }

    /**
     * Liest die editierbaren Felder aus der Config-Datei.
     *
     * @return array<string, scalar>
     */
    private function readFields(): array
    {
        $conf = $this->loadConfig();

        $app  = (array) ($conf['app'] ?? []);
        $appl = (array) ($conf['application'] ?? []);
        $thm  = (array) ($conf['theme'] ?? []);

        // ContainerFactory liest 'hostname' als rpId; Installer schreibt 'domain'.
        // Wir zeigen beide, sofern vorhanden.
        $hostname = (string) ($app['hostname'] ?? $app['domain'] ?? '');

        return [
            'app_name'            => (string) ($appl['name'] ?? $app['name'] ?? 'TowerDNS'),
            'app_hostname'        => $hostname,
            'app_force_https'     => (bool) ($app['force_https'] ?? false),
            'app_debug'           => (bool) ($app['debug'] ?? false),
            'theme_name'          => (string) ($thm['name'] ?? 'default'),
            'pwd_min_length'      => (int) $this->settings->get('security.password.min_length', 16),
            'pwd_min_score'       => (int) $this->settings->get('security.password.min_score', 2),
            'mailer_dsn'          => (string) (($conf['mailer']['dsn'] ?? '') ?: 'null://null'),
            'mailer_from_address' => (string) ($conf['mailer']['from_address'] ?? ''),
        ];
    }

    private function handlePost(
        ServerRequestInterface $request,
        CsrfGuardInterface $guard,
        User $user,
        string $csrfToken,
    ): ResponseInterface {
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse(
                $this->renderer->render('app::settings', [
                    'user'      => $user,
                    'fields'    => $this->readFields(),
                    'error'     => 'Ungültige Anfrage.',
                    'success'   => null,
                    'csrfToken' => $csrfToken,
                ]),
                400,
            );
        }

        $appName     = trim((string) ($body['app_name'] ?? ''));
        $hostname    = trim((string) ($body['app_hostname'] ?? ''));
        $forceHttps  = isset($body['app_force_https']) && $body['app_force_https'] === '1';
        $debug       = isset($body['app_debug'])       && $body['app_debug']       === '1';
        $themeName   = trim((string) ($body['theme_name'] ?? 'default'));
        $pwdMinLen   = max(8, min(128, (int) ($body['pwd_min_length'] ?? 16)));
        $pwdMinScore = max(0, min(4, (int) ($body['pwd_min_score'] ?? 2)));
        $mailerDsn   = trim((string) ($body['mailer_dsn'] ?? 'null://null'));
        $mailerFrom  = trim((string) ($body['mailer_from_address'] ?? ''));

        if ($appName === '') {
            $appName = 'TowerDNS';
        }
        if ($hostname === '') {
            $hostname = 'localhost';
        }
        if ($themeName === '') {
            $themeName = 'default';
        }
        if (!$this->themes->has($themeName)) {
            $fields               = $this->readFields();
            $fields['theme_name'] = $themeName;

            return new HtmlResponse(
                $this->renderer->render('app::settings', [
                    'user'      => $user,
                    'fields'    => $fields,
                    'error'     => 'Das ausgewählte Theme ist nicht installiert oder ungültig.',
                    'success'   => null,
                    'csrfToken' => $csrfToken,
                ]),
                422,
            );
        }
        if ($mailerDsn === '') {
            $mailerDsn = 'null://null';
        }

        $conf = $this->loadConfig();

        // [app] — schreibe in der Variante, die bereits in der Datei steht,
        // damit der bestehende Schlüssel nicht dupliziert wird.
        /** @var array<string, mixed> $appSection */
        $appSection = (array) ($conf['app'] ?? []);
        if (array_key_exists('domain', $appSection)) {
            $appSection['domain'] = $hostname;
        } else {
            $appSection['hostname'] = $hostname;
        }
        $appSection['force_https'] = $forceHttps;
        $appSection['debug']       = $debug;
        $conf['app']               = $appSection;

        // [application]
        /** @var array<string, mixed> $applSection */
        $applSection         = (array) ($conf['application'] ?? []);
        $applSection['name'] = $appName;
        $conf['application'] = $applSection;

        // [theme]
        /** @var array<string, mixed> $thmSection */
        $thmSection         = (array) ($conf['theme'] ?? []);
        $thmSection['name'] = $themeName;
        $conf['theme']      = $thmSection;

        // [security.password] → jetzt DB; aus TOML entfernen, falls noch vorhanden.
        if (isset($conf['security']) && is_array($conf['security'])) {
            unset($conf['security']['password']);
            if ($conf['security'] === []) {
                unset($conf['security']);
            }
        }

        // [mailer]
        /** @var array<string, mixed> $mailerSection */
        $mailerSection                 = (array) ($conf['mailer'] ?? []);
        $mailerSection['dsn']          = $mailerDsn;
        $mailerSection['from_address'] = $mailerFrom;
        $conf['mailer']                = $mailerSection;

        try {
            $toml = Toml::encode($conf);
            $this->configWriter->write($this->configPath, $toml);

            // Runtime-Werte (DB)
            $this->settings->setMany([
                'security.password.min_length' => $pwdMinLen,
                'security.password.min_score'  => $pwdMinScore,
            ], $user->id);
        } catch (\Throwable) {
            return new HtmlResponse(
                $this->renderer->render('app::settings', [
                    'user'      => $user,
                    'fields'    => $this->readFields(),
                    'error'     => $this->translator->translate('http.error.operation-failed'),
                    'success'   => null,
                    'csrfToken' => $csrfToken,
                ]),
                500,
            );
        }

        return new RedirectResponse('/settings?success=' . rawurlencode('Einstellungen wurden gespeichert.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        if (!is_file($this->configPath)) {
            return [];
        }
        $raw = file_get_contents($this->configPath);
        if ($raw === false) {
            return [];
        }
        /** @var array<string, mixed> $data */
        $data = (array) Toml::decode($raw, asArray: true);
        return $data;
    }
}
