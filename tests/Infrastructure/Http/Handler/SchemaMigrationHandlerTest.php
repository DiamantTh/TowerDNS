<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\ServerRequest;
use Laminas\I18n\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Handler\SchemaMigrationHandler;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationManager;

final class SchemaMigrationHandlerTest extends TestCase
{
    public function testInvalidCsrfDoesNotRunMigration(): void
    {
        $renderer = $this->createMock(TemplateRendererInterface::class);
        $renderer->expects(self::once())->method('render')->willReturn('<html></html>');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->willReturnCallback(static fn(string $key): string => $key);
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->method('validateToken')->with('invalid')->willReturn(false);
        $guard->method('generateToken')->willReturn('csrf');
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $manager    = new SchemaMigrationManager($connection, sys_get_temp_dir() . '/towerdns-handler-' . bin2hex(random_bytes(8)) . '.lock');
        $user       = new User('admin', 'admin@example.test', [new Role('superadmin', 'Super Administrator', [Permission::SYSTEM_SCHEMA_MANAGE])]);

        $request = new ServerRequest([], [], 'https://example.test/settings/schema', 'POST')
            ->withParsedBody(['csrf_token' => 'invalid'])
            ->withAttribute(User::class, $user)
            ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard);
        $handler = new SchemaMigrationHandler($renderer, new AuthorizationService(), $manager, $translator);

        self::assertSame(200, $handler->handle($request)->getStatusCode());
        self::assertSame([], $connection->createSchemaManager()->listTableNames());
    }
}
