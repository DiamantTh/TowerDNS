<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\ContainerFactory;
use TowerDNS\Application\Services\WebAuthnService;

final class ContainerFactoryTest extends TestCase
{
    public function testWebAuthnUsesTheDomainWrittenByBothInstallers(): void
    {
        $root = sys_get_temp_dir() . '/towerdns-container-' . bin2hex(random_bytes(8));
        mkdir($root . '/configs', 0o700, true);
        file_put_contents($root . '/configs/config.local.toml', "[app]\ndomain = \"dns.example.test\"\n");

        try {
            $webAuthn = ContainerFactory::create($root)->get(WebAuthnService::class);
            $options  = $webAuthn->createAuthenticationOptions();

            self::assertSame('dns.example.test', $options->rpId);
        } finally {
            unlink($root . '/configs/config.local.toml');
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

            self::assertSame([], $connection->createSchemaManager()->listTableNames());
        } finally {
            @unlink($root . '/configs/database.toml');
            @rmdir($root . '/configs');
            @rmdir($root);
        }
    }
}
