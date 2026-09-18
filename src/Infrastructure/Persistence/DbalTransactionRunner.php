<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Contracts\TransactionRunnerInterface;

final readonly class DbalTransactionRunner implements TransactionRunnerInterface
{
    public function __construct(private Connection $connection) {}

    public function run(callable $operation): mixed
    {
        return $this->connection->transactional(static fn(Connection $connection): mixed => $operation());
    }
}
