<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use Psr\Clock\ClockInterface;
use TowerDNS\Application\DTO\StepUpProof;
use TowerDNS\Application\Repository\StepUpProofNonceRepositoryInterface;

/** Issues and validates short-lived, action-bound MFA evidence. */
final readonly class StepUpProofService
{
    public const int TTL_SECONDS = 300;

    public function __construct(
        private string $signingKey,
        private ClockInterface $clock,
        private StepUpProofNonceRepositoryInterface $nonces,
    ) {
        if (strlen($this->signingKey) < 32) {
            throw new \InvalidArgumentException('The step-up signing key must contain at least 256 bits.');
        }
    }

    public function issue(
        string $actorUserId,
        string $action,
        string $targetId,
        ?string $impersonationSessionId,
        string $method,
        ?string $credentialIdHash = null,
    ): StepUpProof {
        if (!in_array($method, ['password', 'totp', 'webauthn'], true)) {
            throw new \InvalidArgumentException('Unsupported step-up method.');
        }
        if ($credentialIdHash !== null && preg_match('/^[a-f0-9]{64}$/D', $credentialIdHash) !== 1) {
            throw new \InvalidArgumentException('Credential ID hash must be a SHA-256 hex digest.');
        }
        if ($method !== 'password' && $credentialIdHash === null) {
            throw new \InvalidArgumentException('Factor step-up proofs must identify the verified credential.');
        }
        $proof = new StepUpProof(
            $actorUserId,
            $action,
            $targetId,
            $impersonationSessionId,
            $method,
            $this->clock->now()->getTimestamp(),
            bin2hex(random_bytes(16)),
            '',
            $credentialIdHash,
        );

        return new StepUpProof(
            $proof->actorUserId,
            $proof->action,
            $proof->targetId,
            $proof->impersonationSessionId,
            $proof->method,
            $proof->verifiedAt,
            $proof->nonce,
            hash_hmac('sha256', $this->payload($proof), $this->signingKey),
            $proof->credentialIdHash,
        );
    }

    public function isValid(
        ?StepUpProof $proof,
        string $actorUserId,
        string $action,
        string $targetId,
        ?string $impersonationSessionId,
    ): bool {
        if (!$proof instanceof StepUpProof
            || $proof->actorUserId            !== $actorUserId
            || $proof->action                 !== $action
            || $proof->targetId               !== $targetId
            || $proof->impersonationSessionId !== $impersonationSessionId
            || !in_array($proof->method, ['password', 'totp', 'webauthn'], true)
            || ($proof->method !== 'password' && !is_string($proof->credentialIdHash))
            || ($proof->credentialIdHash !== null && preg_match('/^[a-f0-9]{64}$/D', $proof->credentialIdHash) !== 1)
            || preg_match('/^[a-f0-9]{32}$/D', $proof->nonce)     !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $proof->signature) !== 1) {
            return false;
        }

        $now = $this->clock->now()->getTimestamp();
        if ($proof->verifiedAt > $now || $proof->verifiedAt < $now - self::TTL_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $this->payload($proof), $this->signingKey), $proof->signature);
    }

    /**
     * Validates and atomically consumes a proof so concurrent PHP workers
     * cannot use the same non-locking session grant for two mutations.
     */
    public function consumeOnce(
        ?StepUpProof $proof,
        string $actorUserId,
        string $action,
        string $targetId,
        ?string $impersonationSessionId,
    ): bool {
        if (!$proof instanceof StepUpProof
            || !$this->isValid($proof, $actorUserId, $action, $targetId, $impersonationSessionId)) {
            return false;
        }

        return $this->nonces->claim($proof->nonce, $proof->verifiedAt + self::TTL_SECONDS);
    }

    private function payload(StepUpProof $proof): string
    {
        return json_encode([
            'actor'       => $proof->actorUserId,
            'action'      => $proof->action,
            'target'      => $proof->targetId,
            'switch'      => $proof->impersonationSessionId,
            'method'      => $proof->method,
            'credential'  => $proof->credentialIdHash,
            'verified_at' => $proof->verifiedAt,
            'nonce'       => $proof->nonce,
            'purpose'     => 'towerdns-step-up-v2',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
