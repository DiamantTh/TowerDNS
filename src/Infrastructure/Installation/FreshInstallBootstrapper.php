<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Installation;

use Doctrine\DBAL\Connection;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

/**
 * Builds the database portion of a fresh installation.
 *
 * Schema creation is intentionally kept outside a transaction because DDL
 * transaction semantics differ between supported database engines. The
 * mutable bootstrap pairs (user/role and account/owner/limits) are delegated
 * to SchemaManager's transactional seed methods. A retried bootstrap can
 * safely finish the account part after an interruption, but never silently
 * takes ownership of an ambiguous pre-existing database.
 */
final readonly class FreshInstallBootstrapper
{
    public function __construct(private Connection $connection) {}

    /**
     * @throws \RuntimeException when the database is not a safe bootstrap target.
     */
    public function bootstrap(FreshInstallBootstrapRequest $request): FreshInstallBootstrapResult
    {
        if ($request->adminId === '' || $request->accountName === '' || $request->accountSlug === '' || $request->createdAt === '') {
            throw new \RuntimeException('Fresh-install bootstrap data is incomplete.');
        }

        $schema = new SchemaManager($this->connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $schema->seedSystemSettingsDefaults();

        $userCount    = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users');
        $accountCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM accounts');

        if ($userCount === 0 && $accountCount > 0) {
            throw new \RuntimeException('Cannot bootstrap an account without a user.');
        }

        if ($userCount > 1 && $accountCount === 0) {
            throw new \RuntimeException('Cannot determine an owner for an incomplete bootstrap.');
        }

        if ($userCount === 0) {
            $schema->seedFirstUser(
                $request->adminId,
                $request->adminEmail,
                $request->adminPasswordHash,
                $request->adminDisplayName,
            );
            $userCount = 1;
        }

        /** @var string|false $existingAdminId */
        $existingAdminId = $this->connection->fetchOne(
            'SELECT id FROM users ORDER BY created_at ASC, id ASC LIMIT 1',
        );
        if ($existingAdminId === false || $existingAdminId === '') {
            throw new \RuntimeException('Bootstrap user could not be resolved.');
        }

        if ($accountCount === 0) {
            $schema->seedDefaultAccount(
                $existingAdminId,
                $request->accountName,
                $request->accountSlug,
                $request->createdAt,
            );
        }

        return new FreshInstallBootstrapResult(
            $userCount === 1 && $accountCount === 0,
            $existingAdminId,
        );
    }
}
