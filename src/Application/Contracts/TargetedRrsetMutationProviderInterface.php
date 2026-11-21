<?php

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

use TowerDNS\Application\DTO\RrsetMutationCapabilities;

/** Provider-native single-RDATA operations which do not replace sibling records. */
interface TargetedRrsetMutationProviderInterface
{
    public function rrsetMutationCapabilities(): RrsetMutationCapabilities;

    public function appendRrsetRecord(string $zoneId, string $owner, string $type, int $ttl, string $rdata): void;

    public function removeRrsetRecord(string $zoneId, string $owner, string $type, string $rdata): void;
}
