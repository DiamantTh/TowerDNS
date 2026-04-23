<?php

declare(strict_types=1);

namespace TowerDNS\Domain\DNS;

final class Zone
{
    /**
     * @param array<string, scalar|null> $tags
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $providerId,
        public readonly bool $active,
        public readonly array $tags = [],
        public readonly array $metadata = []
    ) {
    }
}
