<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Integration;

/** Prevents the integration suite from dropping tables in an unlabeled database. */
final class DisposableDatabaseGuard
{
    public const string CONFIRMATION = 'I_UNDERSTAND_TESTS_DROP_ALL_TABLES';

    public static function assertSafeTarget(string $backend, string $database, ?string $confirmation): void
    {
        if (!in_array($backend, ['mariadb', 'postgresql'], true)) {
            throw new \InvalidArgumentException('Only external integration databases require this guard.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,58}_(?:test|testing|integration)$/iD', $database) !== 1) {
            throw new \InvalidArgumentException('Integration database names must end in _test, _testing, or _integration.');
        }

        if ($confirmation !== self::CONFIRMATION) {
            throw new \InvalidArgumentException('Set TOWERDNS_TEST_DATABASE_CONFIRMATION only after confirming this database is disposable; the suite drops its tables.');
        }
    }
}
