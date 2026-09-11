<?php

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

/** Provider-specific limits that are not binary capabilities. */
final readonly class ProviderConstraintProfile
{
    /** @param array<string, scalar|list<string>> $details */
    public function __construct(
        public string $rrsetWriteMode,
        public string $readBackConsistency,
        public bool $supportsRfc3597Write,
        public array $details = [],
    ) {}
}
