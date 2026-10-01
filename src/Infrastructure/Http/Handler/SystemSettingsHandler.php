<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Devium\Toml\Toml;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Translator\TranslatorInterface;
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

    #[\Override]
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
        if ($flashSuccess === 'saved') {
            $flashSuccess = $this->translator->translate('settings.success.saved');
        }

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
     * Liest die editierbaren Felder aus der Config-Datei und den Runtime-Settings.
     * SMTP-DSNs koennen Zugangsdaten enthalten und werden daher nie an den Browser
     * ausgegeben. Ein leeres Feld beim Speichern behaelt eine vorhandene DSN bei.
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

        $mailer = $this->mailerFields(trim((string) ($conf['mailer']['dsn'] ?? 'null://null')));

        return [
            'app_name'        => (string) ($appl['name'] ?? $app['name'] ?? 'TowerDNS'),
            'app_hostname'    => $hostname,
            'app_force_https' => (bool) ($app['force_https'] ?? false),
            'app_debug'       => (bool) ($app['debug'] ?? false),
            'theme_name'      => (string) ($thm['name'] ?? 'default'),
            'pwd_min_length'  => (int) $this->settings->get('security.password.min_length', 16),
            'pwd_min_score'   => (int) $this->settings->get('security.password.min_score', 2),
            'hibp_enabled'    => (bool) $this->settings->get('security.password.hibp_enabled', false),
            'hibp_fail_open'  => (bool) $this->settings->get('security.password.hibp_fail_open', true),
            'hibp_timeout'    => (float) $this->settings->get('security.password.hibp_timeout', 3.0),
            ...$mailer,
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
                    'error'     => $this->translator->translate('http.error.invalid-request'),
                    'success'   => null,
                    'csrfToken' => $csrfToken,
                ]),
                400,
            );
        }

        $appName        = trim((string) ($body['app_name'] ?? ''));
        $hostname       = trim((string) ($body['app_hostname'] ?? ''));
        $forceHttps     = isset($body['app_force_https']) && $body['app_force_https'] === '1';
        $debug          = isset($body['app_debug'])       && $body['app_debug']       === '1';
        $themeName      = trim((string) ($body['theme_name'] ?? 'default'));
        $pwdMinLen      = max(8, min(128, (int) ($body['pwd_min_length'] ?? 16)));
        $pwdMinScore    = max(0, min(4, (int) ($body['pwd_min_score'] ?? 2)));
        $hibpEnabled    = isset($body['hibp_enabled'])   && $body['hibp_enabled']   === '1';
        $hibpFailOpen   = isset($body['hibp_fail_open']) && $body['hibp_fail_open'] === '1';
        $hibpTimeout    = max(1.0, min(10.0, (float) ($body['hibp_timeout'] ?? 3.0)));
        $mailerEnabled  = isset($body['mailer_enabled']) && $body['mailer_enabled'] === '1';
        $smtpHost       = trim((string) ($body['smtp_host'] ?? ''));
        $smtpPort       = max(1, min(65535, (int) ($body['smtp_port'] ?? 587)));
        $smtpEncryption = (string) ($body['smtp_encryption'] ?? 'starttls');
        $smtpUsername   = trim((string) ($body['smtp_username'] ?? ''));
        $smtpPassword   = (string) ($body['smtp_password'] ?? '');
        $mailerFrom     = trim((string) ($body['mailer_from_address'] ?? ''));

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
                    'error'     => $this->translator->translate('settings.error.invalid-theme'),
                    'success'   => null,
                    'csrfToken' => $csrfToken,
                ]),
                422,
            );
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

        // [mailer] — DSN bleibt ein internes Symfony-Mailer-Detail. Die UI
        // verarbeitet ausschliesslich SMTP-Felder und gibt kein Kennwort aus.
        /** @var array<string, mixed> $mailerSection */
        $mailerSection  = (array) ($conf['mailer'] ?? []);
        $existingMailer = $this->mailerFields(trim((string) ($mailerSection['dsn'] ?? 'null://null')));
        if (($existingMailer['mailer_editable'] ?? false) === false && ($existingMailer['mailer_configured'] ?? false) === true) {
            // Keep a legacy non-SMTP transport intact; the normal form must not
            // silently turn sendmail/vendor transports into disabled mail.
            $mailerDsn = (string) ($mailerSection['dsn'] ?? 'null://null');
        } elseif (!$mailerEnabled) {
            $mailerDsn = 'null://null';
        } elseif ($smtpHost === '') {
            return $this->invalidMailerResponse($user, $csrfToken);
        } else {
            $existingPassword = $this->smtpPassword((string) ($mailerSection['dsn'] ?? ''));
            $smtpUsername     = $smtpUsername !== '' ? $smtpUsername : (string) ($existingMailer['smtp_username'] ?? '');
            $mailerDsn        = $this->smtpDsn(
                $smtpHost,
                $smtpPort,
                $smtpEncryption,
                $smtpUsername,
                $smtpPassword !== '' ? $smtpPassword : $existingPassword,
            );
        }
        $mailerSection['dsn']          = $mailerDsn;
        $mailerSection['from_address'] = $mailerFrom;
        $conf['mailer']                = $mailerSection;

        try {
            $toml = Toml::encode($conf);
            $this->configWriter->write($this->configPath, $toml);

            // Runtime-Werte (DB)
            $this->settings->setMany([
                'security.password.min_length'     => $pwdMinLen,
                'security.password.min_score'      => $pwdMinScore,
                'security.password.hibp_enabled'   => $hibpEnabled,
                'security.password.hibp_fail_open' => $hibpFailOpen,
                'security.password.hibp_timeout'   => $hibpTimeout,
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

        return new RedirectResponse('/settings?success=saved');
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

    /** @return array<string, scalar> */
    private function mailerFields(string $dsn): array
    {
        if ($dsn === '' || $dsn === 'null://null') {
            return [
                'mailer_configured' => false,
                'mailer_editable'   => true,
                'mailer_enabled'    => false,
                'smtp_host'         => '',
                'smtp_port'         => 587,
                'smtp_encryption'   => 'starttls',
                'smtp_username'     => '',
            ];
        }

        $parts  = parse_url($dsn);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (!is_array($parts) || !in_array($scheme, ['smtp', 'smtps'], true) || !isset($parts['host'])) {
            return [
                'mailer_configured' => true,
                'mailer_editable'   => false,
                'mailer_enabled'    => true,
                'smtp_host'         => '',
                'smtp_port'         => 587,
                'smtp_encryption'   => 'starttls',
                'smtp_username'     => '',
            ];
        }

        parse_str((string) ($parts['query'] ?? ''), $options);
        $encryption = $scheme === 'smtps'
            ? 'tls'
            : (($options['auto_tls'] ?? null) === 'false' ? 'none' : 'starttls');

        return [
            'mailer_configured' => true,
            'mailer_editable'   => true,
            'mailer_enabled'    => true,
            'smtp_host'         => (string) $parts['host'],
            'smtp_port'         => (int) ($parts['port'] ?? ($scheme === 'smtps' ? 465 : 587)),
            'smtp_encryption'   => $encryption,
            'smtp_username'     => isset($parts['user']) ? rawurldecode((string) $parts['user']) : '',
        ];
    }

    private function smtpPassword(string $dsn): string
    {
        $parts = parse_url($dsn);

        return is_array($parts) && isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '';
    }

    private function smtpDsn(string $host, int $port, string $encryption, string $username, string $password): string
    {
        $scheme      = $encryption    === 'tls' ? 'smtps' : 'smtp';
        $query       = $encryption    === 'none' ? '?auto_tls=false' : ($encryption === 'starttls' ? '?require_tls=true' : '');
        $credentials = $username === '' ? '' : rawurlencode($username) . ':' . rawurlencode($password) . '@';

        return $scheme . '://' . $credentials . $host . ':' . $port . $query;
    }

    private function invalidMailerResponse(User $user, string $csrfToken): ResponseInterface
    {
        return new HtmlResponse(
            $this->renderer->render('app::settings', [
                'user'      => $user,
                'fields'    => $this->readFields(),
                'error'     => $this->translator->translate('settings.error.smtp-host-required'),
                'success'   => null,
                'csrfToken' => $csrfToken,
            ]),
            422,
        );
    }
}
