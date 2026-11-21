<?php

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

/** Actual provider-side RRset mutation guarantees (not DNS propagation guarantees). */
final readonly class RrsetMutationCapabilities
{
    public function __construct(
        public bool $conditionalReplace = false,
        public bool $appendRecord = false,
        public bool $removeRecord = false,
        public bool $atomicMultiRecord = false,
        public string $consistency = 'read_modify_write',
    ) {}

    /** @return array{conditional_replace: bool, append_record: bool, remove_record: bool, atomic_multi_record: bool, consistency: string} */
    public function toArray(): array
    {
        return [
            'conditional_replace' => $this->conditionalReplace,
            'append_record'       => $this->appendRecord,
            'remove_record'       => $this->removeRecord,
            'atomic_multi_record' => $this->atomicMultiRecord,
            'consistency'         => $this->consistency,
        ];
    }
}
