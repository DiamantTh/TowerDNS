<?php

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

final readonly class AuthoritativeTlsaCheck
{
    /**
     * @param list<string> $expected
     * @param list<array{nameserver: string, address: ?string, status: string, rcode: ?string, authoritative: bool, records: list<string>, minimum_ttl: ?int, checked_at: string}> $servers
     */
    public function __construct(
        public string $ownerFqdn,
        public string $status,
        public string $checkedAt,
        public string $discoverySource,
        public array $expected,
        public array $servers,
        public ?int $expectedTtl,
        public string $scope,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'owner'                 => $this->ownerFqdn,
            'status'                => $this->status,
            'checked_at'            => $this->checkedAt,
            'discovery_source'      => $this->discoverySource,
            'expected'              => $this->expected,
            'provider_expected_ttl' => $this->expectedTtl,
            'servers'               => $this->servers,
            'scope'                 => $this->scope,
            'dnssec'                => 'not_validated',
            'dane'                  => 'not_tested',
        ];
    }
}
