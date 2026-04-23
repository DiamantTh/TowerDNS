<?php

declare(strict_types=1);

namespace TowerDNS\Domain\DNS;

final class DnssecProfile
{
    /**
     * @param array<string, bool> $features
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public readonly string $zoneId,
        public readonly DnssecState $state,
        public readonly array $features = [],
        public readonly array $metadata = []
    ) {
    }
}
