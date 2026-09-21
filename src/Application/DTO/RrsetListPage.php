<?php

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

use TowerDNS\Domain\DNS\Rrset;

/** Read-only page data and server-derived availability for RRset actions. */
final readonly class RrsetListPage
{
    /** @param list<Rrset> $rrsets */
    public function __construct(
        public string $managedZoneName,
        public array $rrsets,
        public bool $canReplaceRrsets,
        public bool $canDeleteRrsets,
    ) {}
}
