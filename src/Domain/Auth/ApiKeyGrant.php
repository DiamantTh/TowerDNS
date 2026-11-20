<?php

declare(strict_types=1);

namespace TowerDNS\Domain\Auth;

final readonly class ApiKeyGrant
{
    public function __construct(
        public int $id,
        public string $userId,
        public string $scope,
        public int $accountId,
        public int $zoneId,
        public ?string $owner,
    ) {}
}
