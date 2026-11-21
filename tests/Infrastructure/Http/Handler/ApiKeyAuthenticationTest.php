<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Domain\Auth\ApiKeyGrant;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\ClientIpResolver;
use TowerDNS\Infrastructure\Http\Middleware\ApiKeyAuthenticationMiddleware;
use TowerDNS\Infrastructure\Http\Middleware\ForceHttpsMiddleware;
use TowerDNS\Infrastructure\Persistence\DbalApiKeyRepository;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

/** @psalm-api PHPUnit discovers these tests at runtime. */
final class ApiKeyAuthenticationTest extends TestCase
{
    public function testOnlyCurrentScopedKeysAuthenticateAndRevocationIsEnforced(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $schema->seedFirstUser('user-1', 'test@example.test', password_hash('not-a-real-password', PASSWORD_DEFAULT), 'Test');
        $keys  = new DbalApiKeyRepository($connection);
        $users = $this->createMock(UserRepositoryInterface::class);
        $user  = new User('user-1', 'test@example.test');
        $users->method('findById')->with($user->id)->willReturn($user);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturnCallback(static function (ServerRequest $request) use ($user): EmptyResponse {
            self::assertSame($user, $request->getAttribute(User::class));
            self::assertSame('', $request->getHeaderLine('Authorization'));
            $grant = $request->getAttribute(ApiKeyGrant::class);
            self::assertInstanceOf(ApiKeyGrant::class, $grant);
            self::assertSame(7, $grant->zoneId);
            return new EmptyResponse(204);
        });
        $middleware = new ApiKeyAuthenticationMiddleware($keys, $users, new ForceHttpsMiddleware(false, new ClientIpResolver()));
        $request    = new ServerRequest()->withUri(new Uri('https://tower.example.test/api/v1/accounts/42/zones/7/tlsa'));
        $valid      = 'tdns_' . str_repeat('a', 64);
        $expired    = 'tdns_' . str_repeat('b', 64);
        $legacy     = 'tdns_' . str_repeat('c', 64);
        $keys->create($user->id, 'scope test', hash('sha256', $valid), 'tlsa:read', 42, 7, null, new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')));
        $keys->create($user->id, 'expired test', hash('sha256', $expired), 'tlsa:read', 42, 7, null, new \DateTimeImmutable('-1 second', new \DateTimeZone('UTC')));
        $connection->insert('api_keys', ['user_id' => $user->id, 'name' => 'legacy', 'api_key' => hash('sha256', $legacy), 'created_at' => gmdate('Y-m-d H:i:s')]);

        foreach (['', $expired, $legacy, 'bogus'] as $invalid) {
            self::assertSame(401, $middleware->process($request->withHeader('Authorization', 'Bearer ' . $invalid), $handler)->getStatusCode());
        }
        $authenticated = $request->withHeader('Authorization', 'Bearer ' . $valid);
        self::assertSame(400, $middleware->process($authenticated->withUri($request->getUri()->withScheme('http')), $handler)->getStatusCode());
        self::assertSame(204, $middleware->process($authenticated, $handler)->getStatusCode());
        self::assertNotNull($connection->fetchOne('SELECT last_used FROM api_keys WHERE id = 1'));
        self::assertTrue($keys->revoke(1, $user->id));
        self::assertSame(401, $middleware->process($authenticated, $handler)->getStatusCode());
        self::assertSame(hash('sha256', $valid), $connection->fetchOne('SELECT api_key FROM api_keys WHERE id = 1'));
    }
}
