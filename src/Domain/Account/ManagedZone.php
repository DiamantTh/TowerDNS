<?php

declare(strict_types=1);

namespace TowerDNS\Domain\Account;

/** Stable tenant-owned identity for a zone exposed by a provider. */
final readonly class ManagedZone
{
    public function __construct(
        public int $id,
        public int $accountId,
        public int $providerAccountId,
        public string $providerZoneId,
        public string $canonicalName,
        public string $createdAt,
    ) {}
}
