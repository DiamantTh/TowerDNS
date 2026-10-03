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

/**
 * Executes IAM changes in distinct OS processes against the same disposable database.
 *
 * @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible.
 */
final class IamAuthorizationConcurrencyIntegrationTest extends TestCase
{
    public function testConcurrentRoleRemovalAndSuperadminDeactivationKeepOneActiveSuperadmin(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for a real multi-process test.');
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

    /** @return list<array{outcome: 'changed'|'denied'|'error', backend: string, error?: string}> */
    private function runCompetingMutations(string $backend, ?string $sqlitePath, string $scenario): array
    {
        $barrierDir = sys_get_temp_dir() . '/towerdns-iam-barrier-' . bin2hex(random_bytes(8));
        if (!mkdir($barrierDir, 0o700)) {
            self::fail('Could not create private worker-coordination directory.');
        }

        $workers = [];
        foreach ([1, 2] as $position) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->stopWorkers($workers, $barrierDir);
                self::fail('Could not fork IAM integration worker.');
            }

            if ($pid === 0) {
                $readyPath    = $barrierDir . '/worker-' . $position . '.ready';
                $commandPath  = $barrierDir . '/worker-' . $position . '.command';
                $mutatePath   = $barrierDir . '/worker-' . $position . '.mutate';
                $preparedPath = $barrierDir . '/worker-' . $position . '.prepared';
                $resultPath   = $barrierDir . '/worker-' . $position . '.result';

                if (!$this->publishWorkerFile($readyPath, "READY\n")) {
                    exit(70);
                }

                $deadline = hrtime(true) + 25_000_000_000;
                while (!is_file($commandPath) && hrtime(true) < $deadline) {
                    usleep(10_000);
                }
                $command = is_file($commandPath) ? file_get_contents($commandPath) : false;
                if ($command !== "GO\n") {
                    $this->publishWorkerFile($resultPath, json_encode([
                        'outcome' => 'error',
                        'backend' => $backend,
                        'error'   => $command === false ? 'Worker command timed out.' : 'Worker received an invalid command.',
                    ], JSON_THROW_ON_ERROR));
                    exit(71);
                }

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

                    if (!$this->publishWorkerFile($preparedPath, "PREPARED\n")) {
                        throw new \RuntimeException('Could not publish prepared worker state.');
                    }
                    $mutationDeadline = hrtime(true) + 25_000_000_000;
                    while (!is_file($mutatePath) && hrtime(true) < $mutationDeadline) {
                        usleep(10_000);
                    }
                    $mutationCommand = is_file($mutatePath) ? file_get_contents($mutatePath) : false;
                    if ($mutationCommand !== "MUTATE\n") {
                        throw new \RuntimeException(
                            $mutationCommand === false ? 'Worker mutation barrier timed out.' : 'Worker received an invalid mutation command.',
                        );
                    }

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
                $this->publishWorkerFile($resultPath, json_encode([
                    'outcome' => $outcome,
                    'backend' => $backend,
                    'error'   => $errorMessage ?? null,
                ], JSON_THROW_ON_ERROR));
                exit(0);
            }

            $workers[] = ['pid' => $pid, 'position' => $position];
        }

        $readyDeadline = hrtime(true) + 25_000_000_000;
        $allReady      = false;
        while (hrtime(true) < $readyDeadline) {
            $allReady = true;
            foreach ($workers as $worker) {
                $readyPath = $barrierDir . '/worker-' . $worker['position'] . '.ready';
                if (!is_file($readyPath) || file_get_contents($readyPath) !== "READY\n") {
                    $allReady = false;
                    break;
                }
            }
            if ($allReady) {
                break;
            }
            usleep(10_000);
        }

        if (!$allReady) {
            $diagnostics = array_map(
                static fn(array $worker): array => [
                    'position' => $worker['position'],
                    'ready'    => is_file($barrierDir . '/worker-' . $worker['position'] . '.ready'),
                    'result'   => is_file($barrierDir . '/worker-' . $worker['position'] . '.result')
                        ? file_get_contents($barrierDir . '/worker-' . $worker['position'] . '.result')
                        : null,
                ],
                $workers,
            );
            $this->stopWorkers($workers, $barrierDir);
            self::fail("{$backend} concurrency worker readiness timed out: " . json_encode($diagnostics, JSON_THROW_ON_ERROR));
        }
        foreach ($workers as $worker) {
            $this->publishWorkerFile($barrierDir . '/worker-' . $worker['position'] . '.command', "GO\n");
        }

        $preparedDeadline = hrtime(true) + 25_000_000_000;
        $allPrepared      = false;
        while (hrtime(true) < $preparedDeadline) {
            $allPrepared = true;
            foreach ($workers as $worker) {
                $preparedPath = $barrierDir . '/worker-' . $worker['position'] . '.prepared';
                if (!is_file($preparedPath) || file_get_contents($preparedPath) !== "PREPARED\n") {
                    $allPrepared = false;
                    break;
                }
            }
            if ($allPrepared) {
                break;
            }
            usleep(10_000);
        }

        if (!$allPrepared) {
            $diagnostics = array_map(
                static fn(array $worker): array => [
                    'position' => $worker['position'],
                    'prepared' => is_file($barrierDir . '/worker-' . $worker['position'] . '.prepared'),
                    'result'   => is_file($barrierDir . '/worker-' . $worker['position'] . '.result')
                        ? file_get_contents($barrierDir . '/worker-' . $worker['position'] . '.result')
                        : null,
                ],
                $workers,
            );
            $this->stopWorkers($workers, $barrierDir);
            self::fail("{$backend} concurrency worker preparation timed out: " . json_encode($diagnostics, JSON_THROW_ON_ERROR));
        }
        foreach ($workers as $worker) {
            $this->publishWorkerFile($barrierDir . '/worker-' . $worker['position'] . '.mutate', "MUTATE\n");
        }

        $results = [];
        foreach ($workers as $worker) {
            $resultPath     = $barrierDir . '/worker-' . $worker['position'] . '.result';
            $resultDeadline = hrtime(true) + 45_000_000_000;
            while (!is_file($resultPath) && hrtime(true) < $resultDeadline) {
                usleep(10_000);
            }
            $result         = is_file($resultPath) ? file_get_contents($resultPath) : false;
            $resultTimedOut = $result === false;
            if ($resultTimedOut) {
                if (function_exists('posix_kill')) {
                    posix_kill($worker['pid'], SIGTERM);
                }
            }
            $decoded       = is_string($result) ? json_decode($result, true) : null;
            $outcome       = is_array($decoded) ? ($decoded['outcome'] ?? null) : null;
            $resultBackend = is_array($decoded) ? ($decoded['backend'] ?? null) : null;
            $error         = is_array($decoded) ? ($decoded['error'] ?? null) : null;
            if (!in_array($outcome, ['changed', 'denied', 'error'], true)
                || !is_string($resultBackend)
                || ($error !== null && !is_string($error))) {
                $outcome       = 'error';
                $resultBackend = $backend;
                $error         = $resultTimedOut ? 'Worker result timed out.' : 'Worker returned malformed result JSON.';
            }
            $normalizedOutcome = match ($outcome) {
                'changed' => 'changed',
                'denied'  => 'denied',
                default   => 'error',
            };
            $results[] = $this->workerResult($normalizedOutcome, $resultBackend, $error);
            pcntl_waitpid($worker['pid'], $status);
            self::assertTrue(
                pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
                "{$backend} child process {$worker['position']} exited cleanly; result=" . ($result === false ? 'missing' : $result),
            );
        }

        $errors = array_values(array_filter($results, static fn(array $result): bool => $result['outcome'] === 'error'));
        self::assertSame([], $errors, "{$backend} {$scenario} worker error: " . json_encode($errors, JSON_THROW_ON_ERROR));
        foreach ($this->globPaths($barrierDir . '/*') as $path) {
            unlink($path);
        }
        rmdir($barrierDir);

        return $results;
    }

    /** @param list<array{pid: int, position: int}> $workers */
    private function stopWorkers(array $workers, string $barrierDir): void
    {
        foreach ($workers as $worker) {
            foreach (['command', 'mutate'] as $phase) {
                $commandPath = $barrierDir . '/worker-' . $worker['position'] . '.' . $phase;
                if (!is_file($commandPath)) {
                    $this->publishWorkerFile($commandPath, "ABORT\n");
                }
            }
        }
        foreach ($workers as $worker) {
            pcntl_waitpid($worker['pid'], $status);
        }
        foreach ($this->globPaths($barrierDir . '/*') as $path) {
            unlink($path);
        }
        rmdir($barrierDir);
    }

    private function publishWorkerFile(string $path, string $contents): bool
    {
        $pid = getmypid();
        if ($pid === false) {
            return false;
        }
        $temporaryPath = $path . '.tmp-' . $pid;
        if (file_put_contents($temporaryPath, $contents, LOCK_EX) !== strlen($contents)) {
            return false;
        }

        return rename($temporaryPath, $path);
    }

    /**
     * @param 'changed'|'denied'|'error' $outcome
     * @return array{backend: string, outcome: 'changed'|'denied'|'error', error?: string}
     */
    private function workerResult(string $outcome, string $backend, ?string $error): array
    {
        if ($error === null) {
            return ['outcome' => $outcome, 'backend' => $backend];
        }

        return ['outcome' => $outcome, 'backend' => $backend, 'error' => $error];
    }

    /** @return list<string> */
    private function globPaths(string $pattern): array
    {
        $paths = glob($pattern);

        return $paths === false ? [] : $paths;
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

        // parse_url() guarantees string host/path/user/pass and integer port
        // components when present; the required host/path presence is checked
        // above before these values cross into DBAL.
        $host     = $parts['host'];
        $path     = $parts['path'];
        $port     = $parts['port'] ?? ($backend === 'mariadb' ? 3306 : 5432);
        $user     = $parts['user'] ?? '';
        $password = $parts['pass'] ?? '';

        $connectionParams = [
            'driver'   => $backend === 'mariadb' ? 'pdo_mysql' : 'pdo_pgsql',
            'host'     => $host,
            'port'     => $port,
            'dbname'   => ltrim($path, '/'),
            'user'     => rawurldecode($user),
            'password' => rawurldecode($password),
        ];
        if ($backend === 'mariadb') {
            $connectionParams['charset'] = 'utf8mb4';
        }

        $connection = DriverManager::getConnection($connectionParams);
        $connection->fetchOne('SELECT 1');

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
        $tables = array_map(
            static fn(\Doctrine\DBAL\Schema\Name\OptionallyQualifiedName $name): string => $name->getUnqualifiedName()->getValue(),
            $connection->createSchemaManager()->introspectTableNames(),
        );
        if ($backend === 'mariadb') {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        }
        foreach (array_reverse($tables) as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $connection->getDatabasePlatform()->quoteSingleIdentifier($table) . ($backend === 'postgresql' ? ' CASCADE' : ''));
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
