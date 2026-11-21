<?php

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

final readonly class DnssecValidationResult
{
    public function __construct(
        public string $status,
        public string $checkedAt,
        public string $method,
        public ?string $resolver,
        public ?string $reason,
        public ?int $edeCode = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status'     => $this->status,
            'checked_at' => $this->checkedAt,
            'method'     => $this->method,
            'resolver'   => $this->resolver,
            'reason'     => $this->reason,
            'ede_code'   => $this->edeCode,
            'scope'      => 'trusted_recursive_resolver',
        ];
    }
}
