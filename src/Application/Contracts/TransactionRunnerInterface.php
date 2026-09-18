<?php

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

interface TransactionRunnerInterface
{
    /** @template T
     *  @param callable(): T $operation
     *  @return T
     */
    public function run(callable $operation): mixed;
}
