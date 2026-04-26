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
use Mezzio\Twig\TwigEnvironmentFactory;
use Mezzio\Twig\TwigRendererFactory;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Serializer\SerializerInterface;
use TowerDNS\Application\Provider\ProviderRegistry;
use TowerDNS\Application\Repository\RoleRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\DnsManagementService;
use TowerDNS\Application\Services\MailService;
use TowerDNS\Application\Services\PasswordPolicy;
use TowerDNS\Application\Services\TotpService;
use TowerDNS\Application\Services\WebAuthnService;
use TowerDNS\Infrastructure\Clock\SystemClock;
use TowerDNS\Infrastructure\Http\Handler\ProviderCredentialsHandler;
use TowerDNS\Infrastructure\Http\Handler\SystemSettingsHandler;
use TowerDNS\Infrastructure\Http\Middleware\AuthenticationMiddleware;
use TowerDNS\Infrastructure\Http\Middleware\RequireAuthMiddleware;
use TowerDNS\Infrastructure\Persistence\DbalRoleRepository;
use TowerDNS\Infrastructure\Persistence\DbalUserRepository;
use TowerDNS\Infrastructure\Persistence\DbalWebAuthnCredentialRepository;
use TowerDNS\Infrastructure\Provider\Cloudflare\CloudflareProvider;
use TowerDNS\Infrastructure\Provider\DeSEC\DeSECApiClient;
use TowerDNS\Infrastructure\Provider\DeSEC\DeSECProvider;
use TowerDNS\Infrastructure\Provider\Inwx\InwxProvider;
use TowerDNS\Infrastructure\Provider\PowerDNS\PowerDnsProvider;
use TowerDNS\Infrastructure\Twig\TranslatorExtension;
use Twig\Environment;
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
        $dbConf   = $loadToml('database.toml');
        $provConf = $loadToml('providers.toml');

        $debug           = (bool) ($appConf['app']['debug'] ?? false);
        $twigCacheActive = !$debug;

        // ── Build DI container ────────────────────────────────────────────────
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        $builder->addDefinitions([
            // ── Application config consumed by Mezzio factories ───────────────
            'config' => [
                'debug'     => $debug,
                'mezzio'    => [],
                'templates' => [
                    'extension' => 'html.twig',
                    'paths'     => [
                        'app'   => [$projectRoot . '/templates/app'],
                        'error' => [$projectRoot . '/templates/error'],
                    ],
                ],
                'twig' => [
                    'cache_dir'   => $projectRoot . '/cache/twig',
                    'debug'       => $debug,
                    'auto_reload' => $debug,
                ],
                'session' => [
                    'persistence' => [
                        'ext' => [
                            'non_locking'                    => true,
                            'delete_cookie_on_empty_session' => false,
                        ],
                    ],
                ],
            ],

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

                return DriverManager::getConnection($params);
            }),

            // ── Repositories ──────────────────────────────────────────────────
            UserRepositoryInterface::class => \DI\autowire(DbalUserRepository::class),
            RoleRepositoryInterface::class => \DI\autowire(DbalRoleRepository::class),

            // ── Provider registry ─────────────────────────────────────────────
            ProviderRegistry::class => \DI\factory(static function () use ($provConf): ProviderRegistry {
                $registry  = new ProviderRegistry();
                $providers = (array) ($provConf['providers'] ?? []);

                if (isset($providers['desec']['token'])) {
                    $registry->register(new DeSECProvider(new DeSECApiClient(
                        (string) $providers['desec']['token']
                    )));
                }

                if (isset($providers['powerdns']['base_url'], $providers['powerdns']['api_key'])) {
                    $registry->register(new PowerDnsProvider(
                        (string) $providers['powerdns']['base_url'],
                        (string) $providers['powerdns']['api_key'],
                        (string) ($providers['powerdns']['server_id'] ?? 'localhost'),
                    ));
                }

                if (isset($providers['cloudflare']['api_token'])) {
                    $registry->register(new CloudflareProvider(
                        (string) $providers['cloudflare']['api_token']
                    ));
                }

                if (isset($providers['inwx']['username'], $providers['inwx']['password'])) {
                    $registry->register(new InwxProvider(
                        (string) $providers['inwx']['username'],
                        (string) $providers['inwx']['password'],
                    ));
                }

                return $registry;
            }),

            // ── Application services (autowired) ──────────────────────────────
            AuthorizationService::class     => \DI\autowire(),
            DnsManagementService::class     => \DI\autowire(),
            TotpService::class              => \DI\autowire(),
            AuthenticationMiddleware::class => \DI\autowire(),
            RequireAuthMiddleware::class    => \DI\autowire(),            WebAuthnService::class => \DI\factory(
                static function (SerializerInterface $serializer) use ($appConf): WebAuthnService {
                    $app    = (array) ($appConf['app'] ?? []);
                    $rpId   = (string) ($app['hostname'] ?? 'localhost');
                    $rpName = (string) ($app['name'] ?? 'TowerDNS');
                    return new WebAuthnService($serializer, $rpId, $rpName);
                }
            ),

            SystemSettingsHandler::class => \DI\factory(
                static fn(TemplateRendererInterface $renderer, AuthorizationService $authz): SystemSettingsHandler => new SystemSettingsHandler(
                    $renderer,
                    $authz,
                    $projectRoot . '/configs/config.local.toml',
                )
            ),

            ProviderCredentialsHandler::class => \DI\factory(
                static fn(TemplateRendererInterface $renderer, AuthorizationService $authz): ProviderCredentialsHandler => new ProviderCredentialsHandler(
                    $renderer,
                    $authz,
                    $projectRoot . '/configs/providers.toml',
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

            // ── Password policy ───────────────────────────────────────────────
            PasswordPolicy::class => \DI\factory(static function () use ($appConf): PasswordPolicy {
                $sec = (array) ($appConf['security']['password'] ?? []);
                return new PasswordPolicy(
                    (int) ($sec['min_length'] ?? 16),
                    (int) ($sec['min_score'] ?? 0),
                );
            }),

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
            ServerRequestInterface::class => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): callable => (new ServerRequestFactoryFactory())($c)
            ),

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
                static function () use ($appConf, $projectRoot): TranslatorInterface {
                    $locale     = (string) ($appConf['app']['locale'] ?? 'de_DE');
                    $translator = new Translator();
                    $translator->setLocale($locale);
                    $translationsDir = $projectRoot . '/translations';
                    if (is_dir($translationsDir)) {
                        $translator->addTranslationFilePattern(
                            'phpArray',
                            $translationsDir,
                            '%s.php',
                            'default',
                        );
                    }
                    return $translator;
                }
            ),

            // ── Twig ──────────────────────────────────────────────────────────
            Environment::class => \DI\factory(
                static function (\Psr\Container\ContainerInterface $c) use ($twigCacheActive): Environment {
                    $env = (new TwigEnvironmentFactory())($c);
                    $env->addGlobal('twig_cache_active', $twigCacheActive);
                    $env->addExtension($c->get(TranslatorExtension::class));
                    return $env;
                }
            ),
            TemplateRendererInterface::class => \DI\factory(
                static fn(\Psr\Container\ContainerInterface $c): \Mezzio\Twig\TwigRenderer => (new TwigRendererFactory())($c)
            ),
        ]);

        return $builder->build();
    }
}
