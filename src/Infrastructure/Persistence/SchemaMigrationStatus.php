<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

final readonly class SchemaMigrationStatus
{
    /**
     * @param list<array{version: string, description: string}> $executed
     * @param list<array{version: string, description: string}> $pending
     * @param list<string> $schemaIssues
     * @param list<string> $dataIssues
     */
    public function __construct(
        public bool $metadataInitialized,
        public bool $canBaseline,
        public bool $schemaCurrent,
        public array $executed,
        public array $pending,
        public array $schemaIssues,
        public array $dataIssues,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'metadataInitialized' => $this->metadataInitialized,
            'canBaseline'         => $this->canBaseline,
            'schemaCurrent'       => $this->schemaCurrent,
            'executed'            => $this->executed,
            'pending'             => $this->pending,
            'schemaIssues'        => $this->schemaIssues,
            'dataIssues'          => $this->dataIssues,
        ];
    }
}
