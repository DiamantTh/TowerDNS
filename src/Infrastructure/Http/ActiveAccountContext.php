<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http;

use TowerDNS\Domain\Account\Account;

/** Request-scoped selected tenant; it never grants access by itself. */
final readonly class ActiveAccountContext
{
    public function __construct(public ?Account $account = null) {}
}
