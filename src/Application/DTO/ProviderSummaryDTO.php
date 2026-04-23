<?php

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

final class ProviderSummaryDTO
{
    /**
     * @param array<string, bool> $capabilities
     */
    public function __construct(
        public readonly string $id,
        public readonly string $displayName,
        public readonly array $capabilities
    ) {
    }
}
