<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application;

use Doctrine\DBAL\Connection;
use Mezzio\Router\Middleware\ImplicitHeadMiddleware;
use Mezzio\Router\Middleware\ImplicitOptionsMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TowerDNS\Application\ContainerFactory;
use TowerDNS\Application\Repository\SystemSettingsRepositoryInterface;
use TowerDNS\Application\Services\WebAuthnService;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class ContainerFactoryTest extends TestCase
{
    public function testProvidesThePsr17StreamFactoryRequiredByTheHttpPipeline(): void
    {
        $container = ContainerFactory::create(dirname(__DIR__, 2));

        self::assertInstanceOf(StreamFactoryInterface::class, $container->get(StreamFactoryInterface::class));
        self::assertInstanceOf(ResponseFactoryInterface::class, $container->get(ResponseFactoryInterface::class));
        self::assertInstanceOf(ImplicitHeadMiddleware::class, $container->get(ImplicitHeadMiddleware::class));
        self::assertInstanceOf(ImplicitOptionsMiddleware::class, $container->get(ImplicitOptionsMiddleware::class));
    }

    public function testWebAuthnUsesTheDomainWrittenByBothInstallers(): void
    {
        $root = sys_get_temp_dir() . '/towerdns-container-' . bin2hex(random_bytes(8));
        mkdir($root . '/configs', 0o700, true);
        file_put_contents($root . '/configs/config.local.toml', "[app]\ndomain = \"dns.example.test\"\n");
        file_put_contents($root . '/configs/database.toml', "[database]\ndriver = \"pdo_sqlite\"\n\n[database.sqlite]\npath = \":memory:\"\n");

        try {
            $webAuthn = ContainerFactory::create($root)->get(WebAuthnService::class);
            $options  = $webAuthn->createAuthenticationOptions();

            self::assertSame('dns.example.test', $options->rpId);
        } finally {
            unlink($root . '/configs/config.local.toml');
            unlink($root . '/configs/database.toml');
            rmdir($root . '/configs');
            rmdir($root);
        }
    }

    public function testConnectionCreationDoesNotMutateTheApplicationSchema(): void
    {
        $root = sys_get_temp_dir() . '/towerdns-container-' . bin2hex(random_bytes(8));
        mkdir($root . '/configs', 0o700, true);
        $database = ':memory:';
        file_put_contents(
            $root . '/configs/database.toml',
            sprintf(
                "[database]\ndriver = \"pdo_sqlite\"\n\n[database.sqlite]\npath = \"%s\"\n",
                addcslashes($database, '\\"'),
            ),
        );

        try {
            $connection = ContainerFactory::create($root)->get(Connection::class);

            self::assertSame([], $connection->createSchemaManager()->introspectTableNames());
        } finally {
            @unlink($root . '/configs/database.toml');
            @rmdir($root . '/configs');
            @rmdir($root);
        }
    }

    public function testRpIdChangeIsRejectedWhenWebAuthnCredentialsExist(): void
    {
        $root = sys_get_temp_dir() . '/towerdns-rp-guard-' . bin2hex(random_bytes(8));
        mkdir($root . '/configs', 0o700, true);
        file_put_contents($root . '/configs/config.local.toml', "[app]\ndomain = \"new.example.test\"\n");
        file_put_contents($root . '/configs/database.toml', "[database]\ndriver = \"pdo_sqlite\"\n\n[database.sqlite]\npath = \":memory:\"\n");

        try {
            $container  = ContainerFactory::create($root);
            $connection = $container->get(Connection::class);
            $schema     = new SchemaManager($connection);
            $schema->createTablesIfNotExist();
            $schema->seedSystemRoles();
            $schema->seedSystemSettingsDefaults();
            $userId = '33333333-3333-4333-8333-333333333333';
            $schema->seedFirstUser($userId, 'rp-guard@example.test', password_hash('safe-password', PASSWORD_ARGON2ID), 'RP guard');
            $connection->insert('webauthn_credentials', [
                'credential_id' => 'credential-id',
                'user_id'       => $userId,
                'name'          => 'Existing key',
                'data'          => '{}',
                'created_at'    => '2026-01-01 00:00:00',
                'last_used_at'  => null,
                'attachment'    => 'cross-platform',
            ]);
            /** @var SystemSettingsRepositoryInterface $settings */
            $settings = $container->get(SystemSettingsRepositoryInterface::class);
            $settings->set('security.webauthn.rp_id', 'old.example.test', null);
            $settings->set('security.webauthn.origin', 'https://old.example.test', null);

            try {
                $container->get(WebAuthnService::class);
                self::fail('RP ID changes must not silently invalidate existing credentials.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('while credentials exist', $exception->getMessage());
            }
        } finally {
            @unlink($root . '/configs/config.local.toml');
            @unlink($root . '/configs/database.toml');
            @rmdir($root . '/configs');
            @rmdir($root);
        }
    }

    public function testBaseUrlChangeIsRejectedWhenWebAuthnCredentialsExist(): void
    {
        $root = sys_get_temp_dir() . '/towerdns-base-url-guard-' . bin2hex(random_bytes(8));
        mkdir($root . '/configs', 0o700, true);
        file_put_contents($root . '/configs/config.local.toml', "[app]\ndomain = \"dns.example.test\"\nbase_url = \"https://new.example.test\"\n");
        file_put_contents($root . '/configs/database.toml', "[database]\ndriver = \"pdo_sqlite\"\n\n[database.sqlite]\npath = \":memory:\"\n");

        try {
            $container  = ContainerFactory::create($root);
            $connection = $container->get(Connection::class);
            $schema     = new SchemaManager($connection);
            $schema->createTablesIfNotExist();
            $schema->seedSystemRoles();
            $schema->seedSystemSettingsDefaults();
            $userId = '44444444-4444-4444-8444-444444444444';
            $schema->seedFirstUser($userId, 'base-url@example.test', password_hash('safe-password', PASSWORD_ARGON2ID), 'Base URL');
            $connection->insert('webauthn_credentials', [
                'credential_id' => 'credential-id',
                'user_id'       => $userId,
                'name'          => 'Existing key',
                'data'          => '{}',
                'created_at'    => '2026-01-01 00:00:00',
                'last_used_at'  => null,
                'attachment'    => 'cross-platform',
            ]);
            /** @var SystemSettingsRepositoryInterface $settings */
            $settings = $container->get(SystemSettingsRepositoryInterface::class);
            $settings->set('security.webauthn.rp_id', 'dns.example.test', null);
            $settings->set('security.webauthn.origin', 'https://dns.example.test', null);
            $settings->set('security.webauthn.base_url', 'https://old.example.test', null);

            try {
                $container->get(WebAuthnService::class);
                self::fail('Base URL changes must be surfaced while WebAuthn credentials exist.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('base URL configuration changed', $exception->getMessage());
            }
        } finally {
            @unlink($root . '/configs/config.local.toml');
            @unlink($root . '/configs/database.toml');
            @rmdir($root . '/configs');
            @rmdir($root);
        }
    }
}
