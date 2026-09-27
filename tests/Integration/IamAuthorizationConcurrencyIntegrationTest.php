<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace TowerDNS\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\DTO\AuditContext;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\IamAdministrationService;
use TowerDNS\Application\Services\StepUpProofService;
use TowerDNS\Application\Services\UserLifecycleService;
use TowerDNS\Domain\Auth\PermissionRegistry;
use TowerDNS\Infrastructure\Clock\SystemClock;
use TowerDNS\Infrastructure\Persistence\DbalAccountRepository;
use TowerDNS\Infrastructure\Persistence\DbalAccountResourceLimitsRepository;
use TowerDNS\Infrastructure\Persistence\DbalAuditLogRepository;
use TowerDNS\Infrastructure\Persistence\DbalManagedZoneRepository;
use TowerDNS\Infrastructure\Persistence\DbalProviderAccountRepository;
use TowerDNS\Infrastructure\Persistence\DbalRoleRepository;
use TowerDNS\Infrastructure\Persistence\DbalStepUpProofNonceRepository;
use TowerDNS\Infrastructure\Persistence\DbalTransactionRunner;
use TowerDNS\Infrastructure\Persistence\DbalUserRepository;
use TowerDNS\Infrastructure\Persistence\SchemaManager;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationManager;

/** Executes IAM changes in distinct OS processes against the same disposable database. */
final class IamAuthorizationConcurrencyIntegrationTest extends TestCase
{
    public function testConcurrentRoleRemovalAndSuperadminDeactivationKeepOneActiveSuperadmin(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
            self::markTestSkipped('pcntl and Unix socket pairs are required for a real multi-process test.');
        }

        foreach ($this->configuredBackends() as $backend) {
            foreach (['role-removal', 'deactivation'] as $scenario) {
                $sqlitePath = $backend === 'sqlite'
                    ? sys_get_temp_dir() . '/towerdns-iam-concurrency-' . bin2hex(random_bytes(8)) . '.sqlite'
                    : null;

                try {
                    $connection = $this->connect($backend, $sqlitePath);
                    if ($backend !== 'sqlite') {
                        // These DSNs are explicitly disposable integration-test databases.
                        $this->dropTestTables($connection, $backend);
                    }
                    $this->prepareTwoSuperadmins($connection, $backend);
                    $connection->close();

                    $results = $this->runCompetingMutations($backend, $sqlitePath, $scenario);
                    self::assertCount(1, array_filter($results, static fn(array $result): bool => $result['outcome'] === 'changed'), "{$backend} {$scenario}");
                    self::assertCount(1, array_filter($results, static fn(array $result): bool => $result['outcome'] === 'denied'), "{$backend} {$scenario}");

                    $verify = $this->connect($backend, $sqlitePath);
                    self::assertSame(1, (int) $verify->fetchOne("SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id WHERE u.active = TRUE AND ur.role_id = 'superadmin'"), "{$backend} {$scenario}");
                    self::assertSame(2, (int) $verify->fetchOne("SELECT COUNT(*) FROM audit_logs WHERE action IN ('iam.user.roles.changed', 'iam.user.roles.denied', 'iam.user.status.changed', 'iam.user.status.denied')"), "{$backend} {$scenario}");
                    if ($backend !== 'sqlite') {
                        $this->dropTestTables($verify, $backend);
                    }
                    $verify->close();
                } finally {
                    if ($sqlitePath !== null) {
                        @unlink($sqlitePath);
                    }
                }
            }
        }
    }

    private function prepareTwoSuperadmins(Connection $connection, string $backend): void
    {
        $manager = new SchemaMigrationManager(
            $connection,
            sys_get_temp_dir() . '/towerdns-iam-migrate-' . $backend . '-' . bin2hex(random_bytes(6)) . '.lock',
        );
        $status = $manager->migrate();
        self::assertTrue($status->schemaCurrent, $backend);

        $clock  = new SystemClock();
        $users  = new DbalUserRepository($connection, $clock);
        $first  = $this->uuid();
        $second = $this->uuid();
        new SchemaManager($connection)->seedFirstUser($first, $first . '@example.test', password_hash('integration-only-password', PASSWORD_ARGON2ID), 'Root one');
        $users->create($second, $second . '@example.test', password_hash('integration-only-password', PASSWORD_ARGON2ID));
        $connection->insert('user_roles', ['user_id' => $second, 'role_id' => 'superadmin']);

        $connection->executeStatement('CREATE TEMPORARY TABLE towerdns_concurrency_test_users (position INTEGER PRIMARY KEY, user_id VARCHAR(36) NOT NULL)');
        $connection->insert('towerdns_concurrency_test_users', ['position' => 1, 'user_id' => $first]);
        $connection->insert('towerdns_concurrency_test_users', ['position' => 2, 'user_id' => $second]);
        // Temporary tables are connection-scoped, so preserve the generated
        // IDs in a durable test-only table for the worker processes.
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS towerdns_iam_concurrency_fixture (position INTEGER PRIMARY KEY, user_id VARCHAR(36) NOT NULL)');
        $connection->executeStatement('DELETE FROM towerdns_iam_concurrency_fixture');
        $connection->insert('towerdns_iam_concurrency_fixture', ['position' => 1, 'user_id' => $first]);
        $connection->insert('towerdns_iam_concurrency_fixture', ['position' => 2, 'user_id' => $second]);
        $connection->executeStatement('DROP TABLE towerdns_concurrency_test_users');
    }

    /** @return list<array{outcome: string, backend: string, error?: ?string}> */
    private function runCompetingMutations(string $backend, ?string $sqlitePath, string $scenario): array
    {
        $workers = [];
        foreach ([1, 2] as $position) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if (!is_array($pair)) {
                self::fail('Could not create local synchronization sockets.');
            }
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Could not fork IAM integration worker.');
            }

            if ($pid === 0) {
                fclose($pair[0]);
                foreach ($workers as $worker) {
                    fclose($worker['socket']);
                }
                stream_set_timeout($pair[1], 25);
                fwrite($pair[1], "READY\n");
                fflush($pair[1]);
                fgets($pair[1]);

                $workerConnection = null;
                try {
                    $workerConnection = $this->connect($backend, $sqlitePath);
                    if ($backend === 'sqlite') {
                        $workerConnection->executeStatement('PRAGMA busy_timeout = 20000');
                    }
                    $ids      = $workerConnection->fetchFirstColumn('SELECT user_id FROM towerdns_iam_concurrency_fixture ORDER BY position');
                    $actorId  = (string) $ids[$position - 1];
                    $targetId = $scenario === 'role-removal' ? $actorId : (string) $ids[2 - $position];
                    $services = $this->service($workerConnection);
                    $actor    = $services['users']->findById($actorId);
                    if ($actor === null) {
                        throw new \RuntimeException('Synthetic actor could not be loaded.');
                    }
                    $action  = $scenario === 'role-removal' ? StepUpAction::IAM_USER_ROLES : StepUpAction::IAM_USER_STATUS;
                    $proof   = $services['proofs']->issue($actorId, $action, $targetId, null, 'totp');
                    $context = new AuditContext($actorId, $actorId);

                    if ($scenario === 'role-removal') {
                        $services['iam']->syncRoles($actor, $targetId, [], $context, $proof);
                    } else {
                        $services['iam']->setUserActive($actor, $targetId, false, $context, $proof);
                    }
                    $outcome = 'changed';
                } catch (AuthorizationException|\DomainException) {
                    $outcome = 'denied';
                } catch (\Throwable $error) {
                    // This is a disposable test database, and diagnostic text
                    // is needed to distinguish a serialization failure from
                    // an application-level denial. Never include DSN values.
                    $outcome      = 'error';
                    $errorMessage = $error::class . ': ' . $error->getMessage();
                }

                $workerConnection?->close();
                fwrite($pair[1], json_encode([
                    'outcome' => $outcome,
                    'backend' => $backend,
                    'error'   => $errorMessage ?? null,
                ], JSON_THROW_ON_ERROR) . "\n");
                fflush($pair[1]);
                fclose($pair[1]);
                exit(0);
            }

            fclose($pair[1]);
            stream_set_timeout($pair[0], 30);
            $workers[] = ['pid' => $pid, 'socket' => $pair[0]];
        }

        foreach ($workers as $worker) {
            self::assertSame("READY\n", fgets($worker['socket']));
        }
        foreach ($workers as $worker) {
            fwrite($worker['socket'], "GO\n");
            fflush($worker['socket']);
        }

        $results = [];
        foreach ($workers as $worker) {
            $line = fgets($worker['socket']);
            fclose($worker['socket']);
            $decoded = is_string($line) ? json_decode($line, true) : null;
            if (!is_array($decoded)
                || !is_string($decoded['outcome'] ?? null)
                || !is_string($decoded['backend'] ?? null)
                || (isset($decoded['error']) && !is_string($decoded['error']))) {
                $decoded = ['outcome' => 'error', 'backend' => $backend];
            }
            $results[] = [
                'outcome' => $decoded['outcome'],
                'backend' => $decoded['backend'],
                ...((isset($decoded['error']) && is_string($decoded['error'])) ? ['error' => $decoded['error']] : []),
            ];
            pcntl_waitpid($worker['pid'], $status);
            self::assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, "{$backend} child process exited cleanly");
        }

        $errors = array_values(array_filter($results, static fn(array $result): bool => $result['outcome'] === 'error'));
        self::assertSame([], $errors, "{$backend} {$scenario} worker error: " . json_encode($errors, JSON_THROW_ON_ERROR));
        return $results;
    }

    /** @return array{iam: IamAdministrationService, users: DbalUserRepository, proofs: StepUpProofService} */
    private function service(Connection $connection): array
    {
        $clock        = new SystemClock();
        $users        = new DbalUserRepository($connection, $clock);
        $roles        = new DbalRoleRepository($connection);
        $transactions = new DbalTransactionRunner($connection);
        $audit        = new AuditLogService(new DbalAuditLogRepository($connection));
        $lifecycle    = new UserLifecycleService(
            $transactions,
            $users,
            new DbalAccountRepository($connection),
            new DbalAccountResourceLimitsRepository($connection),
            new DbalManagedZoneRepository($connection),
            new DbalProviderAccountRepository($connection),
        );
        $proofs = new StepUpProofService(
            hash('sha256', 'disposable-IAM-concurrency-test-key', true),
            $clock,
            new DbalStepUpProofNonceRepository($connection),
        );

        return [
            'iam'    => new IamAdministrationService($users, $roles, new AuthorizationService(), new PermissionRegistry(), $transactions, $audit, $lifecycle, $proofs),
            'users'  => $users,
            'proofs' => $proofs,
        ];
    }

    private function connect(string $backend, ?string $sqlitePath): Connection
    {
        if ($backend === 'sqlite') {
            if ($sqlitePath === null) {
                throw new \LogicException('A disposable SQLite path is required.');
            }
            $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $sqlitePath]);
            $connection->executeStatement('PRAGMA foreign_keys = ON');
            $connection->executeStatement('PRAGMA busy_timeout = 20000');

            return $connection;
        }

        $envName = $backend === 'mariadb' ? 'TOWERDNS_TEST_MARIADB_DSN' : 'TOWERDNS_TEST_POSTGRESQL_DSN';
        $dsn     = getenv($envName);
        if ($dsn === false || trim($dsn) === '') {
            self::fail(sprintf('%s is required for the configured integration backend.', $envName));
        }
        $parts = parse_url($dsn);
        if (!is_array($parts) || !isset($parts['host'], $parts['path'])) {
            self::fail(sprintf('%s must be a database URL.', $envName));
        }

        $connection = DriverManager::getConnection([
            'driver'   => $backend === 'mariadb' ? 'pdo_mysql' : 'pdo_pgsql',
            'host'     => (string) $parts['host'],
            'port'     => (int) ($parts['port'] ?? ($backend === 'mariadb' ? 3306 : 5432)),
            'dbname'   => ltrim((string) $parts['path'], '/'),
            'user'     => isset($parts['user']) ? rawurldecode((string) $parts['user']) : '',
            'password' => isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '',
            ...($backend === 'mariadb' ? ['charset' => 'utf8mb4'] : []),
        ]);
        $connection->connect();

        return $connection;
    }

    /** @return list<string> */
    private function configuredBackends(): array
    {
        $value    = getenv('TOWERDNS_DB_INTEGRATION_DATABASES');
        $backends = $value === false || trim($value) === '' ? [] : array_map(trim(...), explode(',', strtolower($value)));
        foreach (['mariadb' => 'TOWERDNS_TEST_MARIADB_DSN', 'postgresql' => 'TOWERDNS_TEST_POSTGRESQL_DSN'] as $backend => $variable) {
            if (in_array($backend, $backends, true) && (!is_string(getenv($variable)) || getenv($variable) === '')) {
                self::fail("{$variable} must point to a disposable test database.");
            }
        }

        return ['sqlite', ...array_values(array_intersect(['mariadb', 'postgresql'], $backends))];
    }

    private function dropTestTables(Connection $connection, string $backend): void
    {
        $tables = $connection->createSchemaManager()->listTableNames();
        if ($backend === 'mariadb') {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        }
        foreach (array_reverse($tables) as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $connection->quoteIdentifier($table) . ($backend === 'postgresql' ? ' CASCADE' : ''));
        }
        if ($backend === 'mariadb') {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function uuid(): string
    {
        $bytes = bin2hex(random_bytes(16));

        return substr($bytes, 0, 8) . '-' . substr($bytes, 8, 4) . '-4' . substr($bytes, 13, 3) . '-8' . substr($bytes, 17, 3) . '-' . substr($bytes, 20, 12);
    }
}
