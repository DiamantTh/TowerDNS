<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application;

use Devium\Toml\Toml;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Laminas\I18n\Translator\Translator;
use Laminas\I18n\Translator\TranslatorInterface;
use Laminas\Stratigility\Middleware\ErrorHandler;
use Laminas\Stratigility\MiddlewarePipe;
use Mezzio\Container\ApplicationFactory;
use Mezzio\Container\EmitterFactory;
use Mezzio\Container\ErrorHandlerFactory;
use Mezzio\Container\ErrorResponseGeneratorFactory;
use Mezzio\Container\MiddlewareContainerFactory;
use Mezzio\Container\MiddlewareFactoryFactory;
use Mezzio\Container\RequestHandlerRunnerFactory;
use Mezzio\Container\ResponseFactoryFactory;
use Mezzio\Container\ServerRequestErrorResponseGeneratorFactory;
use Mezzio\Container\ServerRequestFactoryFactory;
use Mezzio\Csrf\CsrfGuardFactoryInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Csrf\SessionCsrfGuardFactory;
use Mezzio\Middleware\ErrorResponseGenerator;
use Mezzio\MiddlewareContainer;
use Mezzio\MiddlewareFactoryInterface;
use Mezzio\Response\ServerRequestErrorResponseGenerator;
use Mezzio\Router\FastRouteRouter;
use Mezzio\Router\FastRouteRouterFactory;
use Mezzio\Router\RouteCollector;
use Mezzio\Router\RouteCollectorInterface;
use Mezzio\Router\RouterInterface;
use Mezzio\Session\Ext\PhpSessionPersistenceFactory;
use Mezzio\Session\SessionMiddleware;
use Mezzio\Session\SessionMiddlewareFactory;
use Mezzio\Session\SessionPersistenceInterface;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Serializer\SerializerInterface;
use TowerDNS\Application\Auth\ActionGroupRegistry;
use TowerDNS\Application\Contracts\AccountProviderFactoryInterface;
use TowerDNS\Application\Contracts\CredentialEncryptorInterface;
use TowerDNS\Application\Contracts\ProviderCredentialSchemaInterface;
use TowerDNS\Application\Contracts\TransactionRunnerInterface;
use TowerDNS\Application\Module\LocalModuleDiscovery;
use TowerDNS\Application\Module\ModuleActionGroupRegistryFactory;
use TowerDNS\Application\Module\ModulePermissionRegistryFactory;
use TowerDNS\Application\Module\ProviderModuleRegistry;
use TowerDNS\Application\Provider\ProviderRegistry;
use TowerDNS\Application\Repository\AccountInvitationRepositoryInterface;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\AccountResourceLimitsRepositoryInterface;
use TowerDNS\Application\Repository\AdminImpersonationSessionRepositoryInterface;
use TowerDNS\Application\Repository\ApiKeyRepositoryInterface;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\PasswordResetTokenRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Repository\RoleRepositoryInterface;
use TowerDNS\Application\Repository\SystemProviderConfigurationStoreInterface;
use TowerDNS\Application\Repository\SystemSettingsRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\ActiveAccountService;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\BreachedPasswordCheckerInterface;
use TowerDNS\Application\Services\CredentialService;
use TowerDNS\Application\Services\HealthStatusService;
use TowerDNS\Application\Services\MailService;
use TowerDNS\Application\Services\NullBreachedPasswordChecker;
use TowerDNS\Application\Services\PasswordAdministrationService;
use TowerDNS\Application\Services\PasswordGenerator;
use TowerDNS\Application\Services\PasswordPolicy;
use TowerDNS\Application\Services\PasswordResetService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Application\Services\SupportedLocales;
use TowerDNS\Application\Services\SystemProviderConfigurationService;
use TowerDNS\Application\Services\TotpSecretService;
use TowerDNS\Application\Services\TotpService;
use TowerDNS\Application\Services\UserLifecycleService;
use TowerDNS\Application\Services\WebAuthnService;
use TowerDNS\Application\Theme\ThemeManager;
use TowerDNS\Domain\Auth\PermissionRegistry;
use TowerDNS\Infrastructure\Clock\SystemClock;
use TowerDNS\Infrastructure\Console\InstallCommand;
use TowerDNS\Infrastructure\Console\ModuleListCommand;
use TowerDNS\Infrastructure\Console\PasswordResetCommand;
use TowerDNS\Infrastructure\Console\RecordListCommand;
use TowerDNS\Infrastructure\Console\RrsetListCommand;
use TowerDNS\Infrastructure\Console\SchemaMigrateCommand;
use TowerDNS\Infrastructure\Console\SchemaStatusCommand;
use TowerDNS\Infrastructure\Console\SchemaValidateCommand;
use TowerDNS\Infrastructure\Console\ZoneListCommand;
use TowerDNS\Infrastructure\Http\ClientIpResolver;
use TowerDNS\Infrastructure\Http\Handler\ForgotPasswordHandler;
use TowerDNS\Infrastructure\Http\Handler\HealthHandler;
use TowerDNS\Infrastructure\Http\Handler\ProviderCredentialsHandler;
use TowerDNS\Infrastructure\Http\Handler\SchemaMigrationHandler;
use TowerDNS\Infrastructure\Http\Handler\SystemSettingsHandler;
use TowerDNS\Infrastructure\Http\Middleware\AuthenticationMiddleware;
use TowerDNS\Infrastructure\Http\Middleware\ClientIpMiddleware;
use TowerDNS\Infrastructure\Http\Middleware\ForceHttpsMiddleware;
use TowerDNS\Infrastructure\Http\Middleware\LocaleMiddleware;
use TowerDNS\Infrastructure\Http\Middleware\RequireAuthMiddleware;
use TowerDNS\Infrastructure\Http\Middleware\SecurityHeaderMiddleware;
use TowerDNS\Infrastructure\Persistence\DbalAccountInvitationRepository;
use TowerDNS\Infrastructure\Persistence\DbalAccountRepository;
use TowerDNS\Infrastructure\Persistence\DbalAccountResourceLimitsRepository;
use TowerDNS\Infrastructure\Persistence\DbalAdminImpersonationSessionRepository;
use TowerDNS\Infrastructure\Persistence\DbalApiKeyRepository;
use TowerDNS\Infrastructure\Persistence\DbalAuditLogRepository;
use TowerDNS\Infrastructure\Persistence\DbalManagedZoneRepository;
use TowerDNS\Infrastructure\Persistence\DbalPasswordResetTokenRepository;
use TowerDNS\Infrastructure\Persistence\DbalProviderAccountRepository;
use TowerDNS\Infrastructure\Persistence\DbalRoleRepository;
use TowerDNS\Infrastructure\Persistence\DbalSystemSettingsRepository;
use TowerDNS\Infrastructure\Persistence\DbalTransactionRunner;
use TowerDNS\Infrastructure\Persistence\DbalUserRepository;
use TowerDNS\Infrastructure\Persistence\DbalWebAuthnCredentialRepository;
use TowerDNS\Infrastructure\Persistence\DbalZoneMembershipRepository;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationManager;
use TowerDNS\Infrastructure\Persistence\TomlSystemProviderConfigurationStore;
use TowerDNS\Infrastructure\Provider\DNSProviderFactory;
use TowerDNS\Infrastructure\Provider\ModuleProviderCredentialSchemaCatalog;
use TowerDNS\Infrastructure\Provider\ProviderAccountAdapterFactory;
use TowerDNS\Infrastructure\Security\HibpRangePasswordChecker;
use TowerDNS\Infrastructure\Ui\SvelteRenderer;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

final class ContainerFactory
{
    public static function create(string $projectRoot): \DI\Container
    {
        // ── Load TOML config files ────────────────────────────────────────────
        $loadToml = static function (string $file) use ($projectRoot): array {
            $path = $projectRoot . '/configs/' . $file;
            if (!is_file($path)) {
                return [];
            }
            $raw = file_get_contents($path);
            if ($raw === false) {
                return [];
            }
            /** @var array<string, mixed> $data */
            $data = (array) Toml::decode($raw, asArray: true);
            return $data;
        };

        $appConf  = $loadToml('config.local.toml');
        $pageUrls = new PageUrls(($appConf['app']['url_style'] ?? 'php') === 'path' ? 'path' : 'php');
        $dbConf   = $loadToml('database.toml');
        $provConf = $loadToml('providers.toml');

        $debug          = (bool) ($appConf['app']['debug'] ?? false);
        $forceHttps     = (bool) ($appConf['app']['force_https'] ?? false);
        $trustedProxies = isset($appConf['app']['trusted_proxies'])
            ? array_values(array_filter((array) $appConf['app']['trusted_proxies'], is_string(...)))
            : [];
        $sessionConf     = (array) ($appConf['session'] ?? []);
        $configuredTheme = (string) ($appConf['theme']['name'] ?? 'default');
        $themeManager    = new ThemeManager($projectRoot, $configuredTheme);
        $enabledModules  = isset($appConf['modules']['enabled'])
            ? array_values(array_filter((array) $appConf['modules']['enabled'], is_string(...)))
            : null;
        $moduleDiscovery = new LocalModuleDiscovery($projectRoot . '/modules', enabledModuleIds: $enabledModules);
        $providerModules = new ProviderModuleRegistry($moduleDiscovery->providerModules());
        $providerFactory = new DNSProviderFactory($providerModules);
        $providerSchemas = new ModuleProviderCredentialSchemaCatalog($providerModules);

        // ── Build DI container ────────────────────────────────────────────────
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        $builder->addDefinitions([
            // ── Application config consumed by Mezzio factories ───────────────
            'config' => [
                'debug'   => $debug,
                'mezzio'  => [],
                'session' => [
                    'name'            => 'towerdns_session',
                    'cookie_lifetime' => 0,
                    'cookie_path'     => '/',
                    'cookie_domain'   => (string) ($appConf['app']['domain'] ?? ''),
                    'cookie_secure'   => (bool) ($sessionConf['cookie_secure'] ?? $forceHttps),
                    'cookie_httponly' => true,
                    'cookie_samesite' => 'Lax',
                    'persistence'     => [
                        'ext' => [
                            'non_locking'                    => true,
                            'delete_cookie_on_empty_session' => true,
                        ],
                    ],
                ],
            ],
            PageUrls::class                                                          => $pageUrls,
            \TowerDNS\Infrastructure\Http\Middleware\VirtualPhpPageMiddleware::class => \DI\factory(
                static fn(): \TowerDNS\Infrastructure\Http\Middleware\VirtualPhpPageMiddleware => new \TowerDNS\Infrastructure\Http\Middleware\VirtualPhpPageMiddleware($pageUrls)
            ),

            // ── Doctrine DBAL ─────────────────────────────────────────────────
            Connection::class => \DI\factory(static function () use ($dbConf, $projectRoot): Connection {
                $db     = $dbConf['database'] ?? [];
                $driver = (string) ($db['driver'] ?? 'pdo_sqlite');

                if ($driver === 'pdo_sqlite') {
                    $params = [
                        'driver' => 'pdo_sqlite',
                        'path'   => (string) ($db['sqlite']['path'] ?? $projectRoot . '/data/database.sqlite'),
                    ];
                } elseif ($driver === 'pdo_pgsql') {
                    $params = [
                        'driver'   => 'pdo_pgsql',
                        'host'     => (string) ($db['host'] ?? 'localhost'),
                        'port'     => (int) ($db['port'] ?? 5432),
                        'dbname'   => (string) ($db['name'] ?? ''),
                        'user'     => (string) ($db['user'] ?? ''),
                        'password' => (string) ($db['password'] ?? (string) (getenv('DB_PASSWORD') ?: '')),
                    ];
                } else {
                    $params = [
                        'driver'   => 'pdo_mysql',
                        'host'     => (string) ($db['host'] ?? 'localhost'),
                        'port'     => (int) ($db['port'] ?? 3306),
                        'dbname'   => (string) ($db['name'] ?? ''),
                        'user'     => (string) ($db['user'] ?? ''),
                        'password' => (string) ($db['password'] ?? (string) (getenv('DB_PASSWORD') ?: '')),
                        'charset'  => (string) ($db['charset'] ?? 'utf8mb4'),
                    ];
                }

                $connection = DriverManager::getConnection($params);
                new \TowerDNS\Infrastructure\Persistence\SqliteConnectionConfigurator()->configure($connection);
                return $connection;
            }),

            SchemaMigrationManager::class => \DI\factory(
                static fn(Connection $connection): SchemaMigrationManager => new SchemaMigrationManager(
                    $connection,
                    $projectRoot . '/data/schema-migrations.lock',
                )
            ),

            // ── Repositories ──────────────────────────────────────────────────
            UserRepositoryInterface::class   => \DI\autowire(DbalUserRepository::class),
            RoleRepositoryInterface::class   => \DI\autowire(DbalRoleRepository::class),
            ApiKeyRepositoryInterface::class => \DI\autowire(DbalApiKeyRepository::class),

            // ── Multi-Tenant repositories ─────────────────────────────────────
            AccountRepositoryInterface::class                   => \DI\autowire(DbalAccountRepository::class),
            AccountInvitationRepositoryInterface::class         => \DI\autowire(DbalAccountInvitationRepository::class),
            AccountResourceLimitsRepositoryInterface::class     => \DI\autowire(DbalAccountResourceLimitsRepository::class),
            ProviderAccountRepositoryInterface::class           => \DI\autowire(DbalProviderAccountRepository::class),
            ManagedZoneRepositoryInterface::class               => \DI\autowire(DbalManagedZoneRepository::class),
            AuditLogRepositoryInterface::class                  => \DI\autowire(DbalAuditLogRepository::class),
            ZoneMembershipRepositoryInterface::class            => \DI\autowire(DbalZoneMembershipRepository::class),
            AdminImpersonationSessionRepositoryInterface::class => \DI\autowire(DbalAdminImpersonationSessionRepository::class),
            PasswordResetTokenRepositoryInterface::class        => \DI\autowire(DbalPasswordResetTokenRepository::class),
            SystemSettingsRepositoryInterface::class            => \DI\autowire(DbalSystemSettingsRepository::class),
            SystemProviderConfigurationStoreInterface::class    => \DI\factory(static fn(): TomlSystemProviderConfigurationStore => new TomlSystemProviderConfigurationStore($projectRoot . '/configs/providers.toml')),
            TransactionRunnerInterface::class                   => \DI\autowire(DbalTransactionRunner::class),

            // ── Credential service (app-key encryption) ───────────────────────
            CredentialService::class => \DI\factory(static function () use ($appConf): CredentialService {
                $b64 = (string) ($appConf['security']['encryption_key'] ?? '');
                if ($b64 === '') {
                    throw new \RuntimeException('security.encryption_key is not configured.');
                }
                return new CredentialService($b64);
            }),
            CredentialEncryptorInterface::class => \DI\get(CredentialService::class),

            // ── Multi-Tenant services ─────────────────────────────────────────
            PermissionService::class                  => \DI\autowire(),
            UserLifecycleService::class               => \DI\autowire(),
            ActiveAccountService::class               => \DI\factory(static fn(\Psr\Container\ContainerInterface $c): ActiveAccountService => new ActiveAccountService($c->get(AccountRepositoryInterface::class), $c->get(UserLifecycleService::class))),
            AuditLogService::class                    => \DI\autowire(),
            PasswordResetService::class               => \DI\autowire(),
            PasswordAdministrationService::class      => \DI\autowire(),
            SystemProviderConfigurationService::class => \DI\autowire(),
            AccountProviderFactoryInterface::class    => \DI\autowire(ProviderAccountAdapterFactory::class),

            // ── Provider registry ─────────────────────────────────────────────
            DNSProviderFactory::class                => $providerFactory,
            ProviderCredentialSchemaInterface::class => $providerSchemas,
            ProviderRegistry::class                  => \DI\factory(static function () use ($provConf, $providerFactory): ProviderRegistry {
                $registry  = new ProviderRegistry();
                $providers = (array) ($provConf['providers'] ?? []);

                foreach ($providers as $type => $credentials) {
                    if (is_string($type) && is_array($credentials) && $providerFactory->credentialsComplete($type, $credentials)) {
                        $registry->register($providerFactory->build($type, $credentials));
                    }
                }

                return $registry;
            }),

            // ── Application services (autowired) ──────────────────────────────
            PermissionRegistry::class  => new ModulePermissionRegistryFactory($moduleDiscovery)->create(),
            ActionGroupRegistry::class => \DI\factory(
                static fn(PermissionRegistry $permissions): ActionGroupRegistry => new ModuleActionGroupRegistryFactory($moduleDiscovery, $permissions)->create()
            ),
            AuthorizationService::class     => \DI\autowire(),
            TotpService::class              => \DI\autowire(),
            TotpSecretService::class        => \DI\autowire(),
            ThemeManager::class             => $themeManager,
            AuthenticationMiddleware::class => \DI\autowire(),
            LocaleMiddleware::class         => \DI\factory(static fn(\Psr\Container\ContainerInterface $c): LocaleMiddleware => new LocaleMiddleware($c->get(TranslatorInterface::class), SupportedLocales::normalize((string) ($appConf['app']['locale'] ?? '')) ?? SupportedLocales::DEFAULT)),
            RequireAuthMiddleware::class    => \DI\autowire(),
            ClientIpMiddleware::class       => \DI\autowire(),
            WebAuthnService::class          => \DI\factory(
                static function (SerializerInterface $serializer) use ($appConf): WebAuthnService {
                    $app = (array) ($appConf['app'] ?? []);
                    // Both installers persist the public host as app.domain.
                    // Keep hostname as a backwards-compatible override for
                    // existing deployments that used the older key.
                    $rpId   = (string) ($app['hostname'] ?? $app['domain'] ?? 'localhost');
                    $rpName = (string) ($app['name'] ?? 'TowerDNS');
                    return new WebAuthnService($serializer, $rpId, $rpName);
                }
            ),

            HealthStatusService::class => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): HealthStatusService => new HealthStatusService(
                    $c->get(Connection::class),
                    $projectRoot,
                    $projectRoot . '/configs/config.local.toml',
                    $projectRoot . '/configs/providers.toml',
                    $moduleDiscovery,
                )
            ),

            HealthHandler::class => \DI\autowire(),

            SystemSettingsHandler::class => \DI\factory(
                static fn(
                    TemplateRendererInterface          $renderer,
                    AuthorizationService               $authz,
                    SystemSettingsRepositoryInterface  $settings,
                    ThemeManager                       $themes,
                    TranslatorInterface                $translator,
                ): SystemSettingsHandler => new SystemSettingsHandler(
                    $renderer,
                    $authz,
                    $settings,
                    $themes,
                    $projectRoot . '/configs/config.local.toml',
                    new \TowerDNS\Infrastructure\Configuration\AtomicConfigurationWriter(),
                    $translator,
                )
            ),
            SchemaMigrationHandler::class => \DI\autowire(),

            ForgotPasswordHandler::class => \DI\factory(
                static function (
                    TemplateRendererInterface $renderer,
                    UserRepositoryInterface $users,
                    PasswordResetTokenRepositoryInterface $tokens,
                    MailService $mail,
                    TranslatorInterface $translator,
                    CacheInterface $cache,
                ) use ($appConf): ForgotPasswordHandler {
                    $app     = (array) ($appConf['app'] ?? []);
                    $baseUrl = rtrim((string) ($app['base_url'] ?? 'http://localhost'), '/');
                    return new ForgotPasswordHandler($renderer, $users, $tokens, $mail, $baseUrl, $translator, $cache);
                }
            ),

            ProviderCredentialsHandler::class => \DI\factory(
                static fn(
                    TemplateRendererInterface $renderer,
                    SystemProviderConfigurationService $providers,
                    AuditLogService $audit,
                    TranslatorInterface $translator,
                ): ProviderCredentialsHandler => new ProviderCredentialsHandler(
                    $renderer,
                    $providers,
                    $audit,
                    $translator,
                )
            ),            // ── PSR-16 cache (Symfony FilesystemAdapter) ──────────────────────
            CacheInterface::class => \DI\factory(
                static function () use ($projectRoot): CacheInterface {
                    $adapter = new FilesystemAdapter(
                        namespace: 'towerdns',
                        defaultLifetime: 0,
                        directory: $projectRoot . '/cache/ratelimit',
                    );
                    return new Psr16Cache($adapter);
                }
            ),

            // ── Symfony Serializer (for WebAuthn credential serialisation) ────
            SerializerInterface::class => \DI\factory(static function (): SerializerInterface {
                $asm = new AttestationStatementSupportManager([
                    new NoneAttestationStatementSupport(),
                ]);
                return new WebauthnSerializerFactory($asm)->create();
            }),

            // ── WebAuthn credential repository ────────────────────────────────
            WebAuthnCredentialRepositoryInterface::class => \DI\autowire(DbalWebAuthnCredentialRepository::class),
            // ── PSR-20 Clock ──────────────────────────────────────────────────
            ClockInterface::class => \DI\autowire(SystemClock::class),

            // ── Breached-password checker (HIBP, optional) ────────────────────
            BreachedPasswordCheckerInterface::class => \DI\factory(
                static function (\Psr\Container\ContainerInterface $c): BreachedPasswordCheckerInterface {
                    $settings = $c->get(SystemSettingsRepositoryInterface::class);
                    if (!(bool) $settings->get('security.password.hibp_enabled', false)) {
                        return new NullBreachedPasswordChecker();
                    }
                    $logger = $c->has(\Psr\Log\LoggerInterface::class)
                        ? $c->get(\Psr\Log\LoggerInterface::class)
                        : new \Psr\Log\NullLogger();
                    /** @var \Psr\Log\LoggerInterface $logger */
                    $timeout = (float) $settings->get('security.password.hibp_timeout', 3.0);
                    return new HibpRangePasswordChecker(
                        new \GuzzleHttp\Client([
                            \GuzzleHttp\RequestOptions::TIMEOUT         => $timeout,
                            \GuzzleHttp\RequestOptions::CONNECT_TIMEOUT => $timeout,
                            \GuzzleHttp\RequestOptions::HTTP_ERRORS     => true,
                        ]),
                        new \Laminas\Diactoros\RequestFactory(),
                        (bool) $settings->get('security.password.hibp_fail_open', true),
                        $logger,
                    );
                }
            ),

            // ── Password policy ───────────────────────────────────────────────
            PasswordPolicy::class => \DI\factory(
                static function (\Psr\Container\ContainerInterface $c): PasswordPolicy {
                    $settings = $c->get(SystemSettingsRepositoryInterface::class);
                    return new PasswordPolicy(
                        (int) $settings->get('security.password.min_length', PasswordPolicy::DEFAULT_MIN_LENGTH),
                        (int) $settings->get('security.password.min_score', PasswordPolicy::DEFAULT_MIN_SCORE),
                        $c->get(BreachedPasswordCheckerInterface::class),
                    );
                }
            ),

            // ── Password generator (uses policy for retry-validation) ─────────
            PasswordGenerator::class => \DI\autowire(),

            // ── CSRF ──────────────────────────────────────────────────────────
            CsrfGuardFactoryInterface::class => \DI\autowire(SessionCsrfGuardFactory::class),
            CsrfMiddleware::class            => \DI\autowire(),

            // ── Mezzio: router ────────────────────────────────────────────────
            RouterInterface::class => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): FastRouteRouter => (new FastRouteRouterFactory())($c)
            ),
            RouteCollectorInterface::class => \DI\factory(
                static fn(RouterInterface $router): RouteCollector => new RouteCollector($router)
            ),

            // ── Mezzio: middleware container & factory ────────────────────────
            MiddlewareContainer::class => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): MiddlewareContainer => (new MiddlewareContainerFactory())($c)
            ),
            MiddlewareFactoryInterface::class => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): MiddlewareFactoryInterface => (new MiddlewareFactoryFactory())($c)
            ),

            // ── Mezzio: pipeline (string key) ─────────────────────────────────
            'Mezzio\ApplicationPipeline' => \DI\factory(
                static fn(): MiddlewarePipe => new MiddlewarePipe()
            ),

            // ── PSR-7 / PSR-17 ────────────────────────────────────────────────
            ResponseInterface::class => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): callable => (new ResponseFactoryFactory())($c)
            ),
            ResponseFactoryInterface::class => \DI\autowire(\Laminas\Diactoros\ResponseFactory::class),
            ServerRequestInterface::class   => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): callable => (new ServerRequestFactoryFactory())($c)
            ),
            StreamFactoryInterface::class => \DI\autowire(\Laminas\Diactoros\StreamFactory::class),

            // ── Mezzio: emitter & runner ──────────────────────────────────────
            EmitterInterface::class => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): EmitterInterface => (new EmitterFactory())($c)
            ),
            ServerRequestErrorResponseGenerator::class => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): ServerRequestErrorResponseGenerator => (new ServerRequestErrorResponseGeneratorFactory())($c)
            ),
            RequestHandlerRunnerInterface::class => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): \Laminas\HttpHandlerRunner\RequestHandlerRunner => (new RequestHandlerRunnerFactory())($c)
            ),

            // ── Mezzio: application ───────────────────────────────────────────
            \Mezzio\Application::class => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): \Mezzio\Application => (new ApplicationFactory())($c)
            ),

            // ── Mezzio: error handling ────────────────────────────────────────
            ErrorResponseGenerator::class => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): ErrorResponseGenerator => (new ErrorResponseGeneratorFactory())($c)
            ),
            ErrorHandler::class => \DI\factory(
                static function (\Psr\Container\ContainerInterface $c) use ($appConf): ErrorHandler {
                    $handler = (new ErrorHandlerFactory())($c);

                    $sentryDsn = (string) ($appConf['sentry']['dsn'] ?? '');
                    if ($sentryDsn !== '') {
                        \Sentry\init(['dsn' => $sentryDsn]);
                        $handler->attachListener(
                            static function (\Throwable $error): void {
                                \Sentry\captureException($error);
                            }
                        );
                    }

                    return $handler;
                }
            ),

            // ── Session ───────────────────────────────────────────────────────
            SessionPersistenceInterface::class => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): \Mezzio\Session\Ext\PhpSessionPersistence => (new PhpSessionPersistenceFactory())($c)
            ),
            SessionMiddleware::class => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): SessionMiddleware => (new SessionMiddlewareFactory())($c)
            ),
            SecurityHeaderMiddleware::class => \DI\factory(
                static fn(): SecurityHeaderMiddleware => new SecurityHeaderMiddleware($forceHttps)
            ),
            ClientIpResolver::class => \DI\factory(
                static fn(): ClientIpResolver => new ClientIpResolver($trustedProxies)
            ),
            ForceHttpsMiddleware::class => \DI\factory(
                static fn(ClientIpResolver $ips): ForceHttpsMiddleware => new ForceHttpsMiddleware($forceHttps, $ips)
            ),

            // ── Symfony Mailer ────────────────────────────────────────────────
            MailService::class => \DI\factory(
                static function () use ($appConf): MailService {
                    $mailerConf  = (array) ($appConf['mailer'] ?? []);
                    $dsn         = (string) ($mailerConf['dsn'] ?? 'null://null');
                    $fromAddress = (string) ($mailerConf['from_address'] ?? 'noreply@localhost');
                    $fromName    = (string) ($appConf['application']['name'] ?? 'TowerDNS');
                    return new MailService($dsn, $fromAddress, $fromName);
                }
            ),

            // ── Laminas I18n Translator ───────────────────────────────────────
            TranslatorInterface::class => \DI\factory(
                static function () use ($appConf, $projectRoot, $moduleDiscovery): TranslatorInterface {
                    $locale     = SupportedLocales::normalize((string) ($appConf['app']['locale'] ?? '')) ?? SupportedLocales::DEFAULT;
                    $translator = new Translator();
                    $translator->setLocale($locale);
                    $translator->setFallbackLocale(SupportedLocales::DEFAULT);
                    $translationsDir = $projectRoot . '/translations';
                    if (is_dir($translationsDir)) {
                        $translator->addTranslationFilePattern(
                            'phpArray',
                            $translationsDir,
                            '%s.php',
                            'default',
                        );
                    }
                    foreach ($moduleDiscovery->translationDirectories() as $directory) {
                        $translator->addTranslationFilePattern('phpArray', $directory, '%s.php', 'default');
                    }
                    return $translator;
                }
            ),

            // ── Twig ──────────────────────────────────────────────────────────
            TemplateRendererInterface::class => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): SvelteRenderer => new SvelteRenderer(
                    $themeManager,
                    $debug,
                    $c->get(TranslatorInterface::class),
                    $pageUrls,
                )
            ),

            // ── Console commands ─────────────────────────────────────────────
            InstallCommand::class => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): InstallCommand => new InstallCommand(
                    $projectRoot,
                    $providerFactory,
                    $c->get(TranslatorInterface::class),
                )
            ),
            PasswordResetCommand::class => \DI\factory(
                static fn(): PasswordResetCommand => new PasswordResetCommand($projectRoot)
            ),
            ZoneListCommand::class       => \DI\autowire(),
            RecordListCommand::class     => \DI\autowire(),
            RrsetListCommand::class      => \DI\autowire(),
            ModuleListCommand::class     => \DI\autowire(),
            SchemaStatusCommand::class   => \DI\autowire(),
            SchemaMigrateCommand::class  => \DI\autowire(),
            SchemaValidateCommand::class => \DI\autowire(),
            LocalModuleDiscovery::class  => $moduleDiscovery,
        ]);

        return $builder->build();
    }
}
