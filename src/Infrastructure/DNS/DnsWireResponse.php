<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\DNS;

final readonly class DnsWireResponse
{
    /** @param list<string> $tlsaRecords */
    public function __construct(
        public int $rcode,
        public bool $authoritative,
        public bool $truncated,
        public array $tlsaRecords,
        public ?int $minimumTtl,
    ) {}
}
