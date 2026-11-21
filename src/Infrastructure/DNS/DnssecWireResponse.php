<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\DNS;

/** @phpstan-type DnsRecord array{owner: string, type: int, class: int, ttl: int, rdata: string, offset: int} */
final readonly class DnssecWireResponse
{
    /** @param list<array{owner: string, type: int, class: int, ttl: int, rdata: string, offset: int}> $answers
     *  @param list<int> $edeCodes
     */
    public function __construct(
        public int $rcode,
        public bool $authenticatedData,
        public array $answers,
        public array $edeCodes,
        public string $packet,
    ) {}
}
