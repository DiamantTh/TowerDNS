<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Backup;

use Doctrine\DBAL\Connection;

/** Native database clients are invoked only from explicit CLI commands. */
final readonly class NativeBackupTools
{
    public function __construct(private string $scratch) {}

    public function dump(Connection $connection, string $type, string $destination): string
    {
        $tool    = $type === 'mariadb' ? 'mariadb-dump' : 'pg_dump';
        $version = $type === 'postgresql' ? $this->postgresVersion($connection, $tool) : $this->version($tool);
        if ($type === 'mariadb') {
            $other = (int) $connection->fetchOne("SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE() AND (table_type <> 'BASE TABLE' OR engine <> 'InnoDB')");
            if ($other !== 0) {
                throw new BackupException('MariaDB backup requires a dedicated database with InnoDB base tables only.');
            }
            $this->databaseCommand(
                $connection,
                $type,
                $tool,
                ['--single-transaction', '--quick', '--skip-lock-tables', '--skip-add-locks', '--skip-triggers', '--skip-add-drop-table', '--hex-blob'],
                null,
                $destination
            );
        } else {
            $this->databaseCommand(
                $connection,
                $type,
                $tool,
                ['--no-password', '--format=custom', '--schema=public', '--no-owner', '--no-acl'],
                null,
                $destination
            );
        }
        return $version;
    }

    public function restore(Connection $connection, string $type, string $source): void
    {
        if ($type === 'mariadb') {
            $this->version('mariadb');
            $this->databaseCommand($connection, $type, 'mariadb', ['--binary-mode', '--local-infile=0'], $source, null);
        } else {
            $this->postgresVersion($connection, 'pg_restore');
            $list = $this->scratch . '/restore-toc';
            try {
                $this->run(['pg_restore', '--list', '--', $source], null, $list);
                $toc = file_get_contents($list);
                // The verified target already owns an empty public schema.
                // Keep the table and data entries and skip creation of the schema itself.
                $selected = is_string($toc) ? preg_replace('/^[0-9]+; [0-9]+ [0-9]+ SCHEMA - public(?= |$)/m', ';$0', $toc) : null;
                if (!is_string($selected) || file_put_contents($list, $selected) !== strlen($selected)) {
                    throw new BackupException('Unable to prepare the PostgreSQL restore table of contents.');
                }
                $this->databaseCommand(
                    $connection,
                    $type,
                    'pg_restore',
                    ['--single-transaction', '--exit-on-error', '--no-owner', '--no-acl', '--no-password', '--use-list=' . $list],
                    $source,
                    null,
                );
            } finally {
                if (is_file($list)) {
                    unlink($list);
                }
            }
        }
    }

    public function inspectPostgres(string $source): void
    {
        $this->run(['pg_restore', '--list', '--', $source], null, null);
    }

    /** @param list<string> $options */
    private function databaseCommand(Connection $connection, string $type, string $tool, array $options, ?string $stdin, ?string $stdout): void
    {
        /** @psalm-suppress InternalMethod DBAL has no public accessor for the existing native client connection credentials. */
        $params   = $connection->getParams();
        $host     = $params['host'] ?? 'localhost';
        $port     = (string) ($params['port'] ?? ($type === 'mariadb' ? '3306' : '5432'));
        $name     = $params['dbname']      ?? '';
        $user     = $params['user']        ?? '';
        $password = $params['password']    ?? '';
        $socket   = $params['unix_socket'] ?? '';
        if ($type === 'mariadb' && $host === 'localhost' && $socket === '') {
            $defaultSocket = ini_get('pdo_mysql.default_socket');
            if ($defaultSocket === false || $defaultSocket === '') {
                throw new BackupException('Configure an explicit MariaDB TCP host or a PDO Unix socket path.');
            }
            $socket = $defaultSocket;
        }
        foreach ([$host, $port, $name, $user, $password, $socket] as $value) {
            if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new BackupException('Database connection parameters cannot be represented in native client credentials.');
            }
        }
        if ($name === '' || $name[0] === '-' || $connection->getDatabase() !== $name || $user === '' || $host === '' || !ctype_digit($port)) {
            throw new BackupException('Native database clients require explicit host, port, database and user.');
        }
        $credentialFile = $this->scratch . '/client-credentials';
        try {
            if ($type === 'mariadb') {
                $quote = static fn(string $value): string => '"' . addcslashes($value, '\\"') . '"';
                $this->privateText($credentialFile, "[client]\nuser=" . $quote($user) . "\npassword=" . $quote($password) . "\n");
                $endpoint = $socket === ''
                    ? ['--protocol=tcp', '--host=' . $host, '--port=' . $port]
                    : ['--protocol=socket', '--socket=' . $socket];
                $args = [$tool, '--defaults-file=' . $credentialFile, ...$endpoint, ...$options, '--', $name];
                $this->run($args, $stdin, $stdout);
            } else {
                $escape = static fn(string $value): string => addcslashes($value, '\\:');
                $this->privateText($credentialFile, implode(':', array_map($escape, [$host, $port, $name, $user, $password])) . "\n");
                // Environment is built explicitly. PGOPTIONS, service files and
                // ambient password variables cannot change the target database.
                if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.-]*\z/D', $name) !== 1 || str_contains($host, ',') || str_starts_with($host, '/')) {
                    throw new BackupException('Use a simple PostgreSQL database name and a single TCP host.');
                }
                $args = [$tool, '--host=' . $host, '--port=' . $port, '--username=' . $user, '--dbname=' . $name, ...$options];
                $this->run($args, $stdin, $stdout, ['PGPASSFILE' => $credentialFile, 'PGCONNECT_TIMEOUT' => '10']);
            }
        } finally {
            if (is_file($credentialFile)) {
                unlink($credentialFile);
            }
        }
    }

    private function postgresVersion(Connection $connection, string $tool): string
    {
        $version     = $this->version($tool);
        $serverMajor = intdiv((int) $connection->fetchOne('SHOW server_version_num'), 10000);
        if (preg_match('/\(PostgreSQL\) ([0-9]+)\./', $version, $parts) !== 1 || (int) $parts[1] !== $serverMajor) {
            throw new BackupException(sprintf('Use %s from PostgreSQL major version %d for this database server.', $tool, $serverMajor));
        }
        return $version;
    }

    private function version(string $tool): string
    {
        $path = $this->scratch . '/version';
        try {
            $this->run([$tool, '--version'], null, $path);
            $version = trim((string) file_get_contents($path, length: 256));
            if (!preg_match('/\A[ -~]{1,255}\z/D', $version)) {
                throw new BackupException('Cannot determine the native database client version.');
            }
            return $version;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function privateText(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) !== strlen($contents) || !chmod($path, 0o600)) {
            throw new BackupException('Unable to protect native client credentials.');
        }
    }

    /**
     * @param non-empty-list<string> $argv
     * @param array<string,string> $extraEnv
     */
    private function run(array $argv, ?string $stdin, ?string $stdout, array $extraEnv = []): void
    {
        if (PHP_SAPI !== 'cli' || !function_exists('proc_open')) {
            throw new BackupException('Native database tools require proc_open in the CLI runtime.');
        }
        $path  = getenv('PATH');
        $env   = ['PATH' => $path === false || $path === '' ? '/usr/bin:/bin' : $path, 'LC_ALL' => 'C', ...$extraEnv];
        $pipes = [];
        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- Explicit CLI-only native database clients, with an argv array and private stdin/stdout.
        $process = @proc_open($argv, [
            ['file', $stdin ?? '/dev/null', 'r'],
            ['file', $stdout ?? '/dev/null', 'w'],
            ['file', '/dev/null', 'w'],
        ], $pipes, $this->scratch, $env);
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new BackupException(sprintf('%s failed or is unavailable. Check the installed client, database access and client/server compatibility.', $argv[0]));
        }
    }
}
