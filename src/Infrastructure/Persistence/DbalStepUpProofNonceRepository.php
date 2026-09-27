<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TowerDNS\Application\Repository\StepUpProofNonceRepositoryInterface;

final readonly class DbalStepUpProofNonceRepository implements StepUpProofNonceRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    public function claim(string $nonce, int $expiresAt): bool
    {
        // Expired rows are short-lived security metadata, not an audit trail.
        // Pruning here avoids requiring a worker or scheduled task on shared hosting.
        $this->connection->executeStatement(
            'DELETE FROM step_up_proof_nonces WHERE expires_at < ?',
            [time()],
        );

        try {
            $this->connection->insert('step_up_proof_nonces', [
                'nonce'      => $nonce,
                'expires_at' => $expiresAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
