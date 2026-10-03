<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http;

use TowerDNS\Domain\Account\AdminImpersonationSession;
use TowerDNS\Domain\Auth\User;

/** @psalm-api Request-attribute contract shared by HTTP middleware and handlers. */
final readonly class ImpersonationContext
{
    public function __construct(
        public User $actor,
        public User $effectiveUser,
        public ?int $effectiveAccountId = null,
        public ?AdminImpersonationSession $session = null,
    ) {}
}
