<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

use Devium\Toml\Toml;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Laminas\Stratigility\Middleware\ErrorHandler;
use Laminas\Stratigility\MiddlewarePipe;
use Mezzio\Container\ApplicationFactory;
use Mezzio\Container\ApplicationPipelineFactory;
use Mezzio\Container\EmitterFactory;
use Mezzio\Container\ErrorHandlerFactory;
use Mezzio\Container\ErrorResponseGeneratorFactory;
use Mezzio\Container\MiddlewareContainerFactory;
use Mezzio\Container\MiddlewareFactoryFactory;
use Mezzio\Container\RequestHandlerRunnerFactory;
use Mezzio\Container\ResponseFactoryFactory;
use Mezzio\Container\ServerRequestFactoryFactory;
use Mezzio\MiddlewareContainer;
use Mezzio\MiddlewareFactory;
use Mezzio\MiddlewareFactoryInterface;
use Mezzio\Middleware\ErrorResponseGenerator;
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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TowerDNS\Application\Provider\ProviderRegistry;
use TowerDNS\Application\Repository\RoleRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\DnsManagementService;
use TowerDNS\Infrastructure\Http\Middleware\AuthenticationMiddleware;
use TowerDNS\Infrastructure\Http\Middleware\RequireAuthMiddleware;
use TowerDNS\Infrastructure\Persistence\DbalRoleRepository;
use TowerDNS\Infrastructure\Persistence\DbalUserRepository;
use TowerDNS\Infrastructure\Provider\Cloudflare\CloudflareProvider;
use TowerDNS\Infrastructure\Provider\DeSEC\DeSECApiClient;
use TowerDNS\Infrastructure\Provider\DeSEC\DeSECProvider;
use TowerDNS\Infrastructure\Provider\Inwx\InwxProvider;
use TowerDNS\Infrastructure\Provider\PowerDNS\PowerDnsProvider;
use Twig\Environment;

defined('PROJECT_ROOT') || define('PROJECT_ROOT', dirname(__DIR__));

// ── Load TOML config files ────────────────────────────────────────────────────

// Load a TOML file from config/, returns empty array if missing
$loadToml = static function (string $file): array {
    $path = PROJECT_ROOT . '/config/' . $file;
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

$debug = (bool) ($appConf['app']['debug'] ?? false);

// ── Build DI container ────────────────────────────────────────────────────────

$builder = new ContainerBuilder();
$builder->useAutowiring(true);

$builder->addDefinitions([

    // ── Application config consumed by Mezzio factories ───────────────────
    'config' => [
        'debug'  => $debug,
        'mezzio' => [],
        'templates' => [
            'extension' => 'html.twig',
            'paths'     => [
                PROJECT_ROOT . '/resources/templates',
            ],
        ],
        'twig' => [
            'cache_dir'   => PROJECT_ROOT . '/var/cache/twig',
            'debug'       => $debug,
            'auto_reload' => $debug,
        ],
        'session' => [
            'persistence' => [
                'ext' => [
                    'non_locking' => true,
                    'delete_cookie_on_empty_session' => false,
                ],
            ],
        ],
    ],

    // ── Doctrine DBAL ──────────────────────────────────────────────────────
    Connection::class => DI\factory(static function () use ($dbConf): Connection {
        $db = $dbConf['database'] ?? [];
        $driver = (string) ($db['driver'] ?? 'pdo_sqlite');

        if ($driver === 'pdo_sqlite') {
            $params = [
                'driver' => 'pdo_sqlite',
                'path'   => (string) ($db['sqlite']['path'] ?? PROJECT_ROOT . '/var/database.sqlite'),
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

    // ── Repositories ──────────────────────────────────────────────────────
    UserRepositoryInterface::class => DI\autowire(DbalUserRepository::class),
    RoleRepositoryInterface::class => DI\autowire(DbalRoleRepository::class),

    // ── Provider registry ─────────────────────────────────────────────────
    ProviderRegistry::class => DI\factory(static function () use ($provConf): ProviderRegistry {
        $registry  = new ProviderRegistry();
        $providers = (array) ($provConf['providers'] ?? []);

        if (isset($providers['desec']['token'])) {
            $registry->register(new DeSECProvider(new DeSECApiClient(
                (string) $providers['desec']['token']
            )));
        }

        if (
            isset($providers['powerdns']['base_url'], $providers['powerdns']['api_key'])
        ) {
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

    // ── Application services (autowired) ──────────────────────────────────
    AuthorizationService::class  => DI\autowire(),
    DnsManagementService::class  => DI\autowire(),
    AuthenticationMiddleware::class => DI\autowire(),
    RequireAuthMiddleware::class    => DI\autowire(),

    // ── Mezzio: router ────────────────────────────────────────────────────
    RouterInterface::class => DI\factory(static function (\Psr\Container\ContainerInterface $c): FastRouteRouter {
        return (new FastRouteRouterFactory())($c);
    }),
    RouteCollectorInterface::class => DI\factory(static function (RouterInterface $router): RouteCollector {
        return new RouteCollector($router);
    }),

    // ── Mezzio: middleware container & factory ────────────────────────────
    MiddlewareContainer::class => DI\factory(static function (\Psr\Container\ContainerInterface $c): MiddlewareContainer {
        return (new MiddlewareContainerFactory())($c);
    }),
    MiddlewareFactoryInterface::class => DI\factory(static function (\Psr\Container\ContainerInterface $c): MiddlewareFactory {
        return (new MiddlewareFactoryFactory())($c);
    }),

    // ── Mezzio: pipeline (string key — not a real class) ──────────────────
    'Mezzio\ApplicationPipeline' => DI\factory(static function (): MiddlewarePipe {
        return new MiddlewarePipe();
    }),

    // ── PSR-7 / PSR-17 ────────────────────────────────────────────────────
    ResponseInterface::class => DI\factory(static function (\Psr\Container\ContainerInterface $c): callable {
        return (new ResponseFactoryFactory())($c);
    }),
    ServerRequestInterface::class => DI\factory(static function (\Psr\Container\ContainerInterface $c): callable {
        return (new ServerRequestFactoryFactory())($c);
    }),

    // ── Mezzio: emitter & runner ──────────────────────────────────────────
    EmitterInterface::class => DI\factory(static function (\Psr\Container\ContainerInterface $c): EmitterInterface {
        return (new EmitterFactory())($c);
    }),
    ServerRequestErrorResponseGenerator::class => DI\factory(
        static function (\Psr\Container\ContainerInterface $c): ServerRequestErrorResponseGenerator {
            return (new \Mezzio\Container\ServerRequestErrorResponseGeneratorFactory())($c);
        }
    ),
    RequestHandlerRunnerInterface::class => DI\factory(
        static function (\Psr\Container\ContainerInterface $c): \Laminas\HttpHandlerRunner\RequestHandlerRunner {
            return (new RequestHandlerRunnerFactory())($c);
        }
    ),

    // ── Mezzio: application ───────────────────────────────────────────────
    \Mezzio\Application::class => DI\factory(static function (\Psr\Container\ContainerInterface $c): \Mezzio\Application {
        return (new ApplicationFactory())($c);
    }),

    // ── Mezzio: error handling ────────────────────────────────────────────
    ErrorResponseGenerator::class => DI\factory(static function (\Psr\Container\ContainerInterface $c): ErrorResponseGenerator {
        return (new ErrorResponseGeneratorFactory())($c);
    }),
    ErrorHandler::class => DI\factory(static function (\Psr\Container\ContainerInterface $c): ErrorHandler {
        return (new ErrorHandlerFactory())($c);
    }),

    // ── Session ───────────────────────────────────────────────────────────
    SessionPersistenceInterface::class => DI\factory(
        static function (\Psr\Container\ContainerInterface $c): \Mezzio\Session\Ext\PhpSessionPersistence {
            return (new PhpSessionPersistenceFactory())($c);
        }
    ),
    SessionMiddleware::class => DI\factory(static function (\Psr\Container\ContainerInterface $c): SessionMiddleware {
        return (new SessionMiddlewareFactory())($c);
    }),

    // ── Twig ──────────────────────────────────────────────────────────────
    Environment::class => DI\factory(static function (\Psr\Container\ContainerInterface $c): Environment {
        return (new TwigEnvironmentFactory())($c);
    }),
    TemplateRendererInterface::class => DI\factory(
        static function (\Psr\Container\ContainerInterface $c): \Mezzio\Twig\TwigRenderer {
            return (new TwigRendererFactory())($c);
        }
    ),
]);

return $builder->build();
