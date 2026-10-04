<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

/** A signed, short-lived proof that a second factor was verified. */
final readonly class StepUpProof
{
    public function __construct(
        public string $actorUserId,
        public string $action,
        public string $targetId,
        public ?string $impersonationSessionId,
        public string $method,
        public int $verifiedAt,
        public string $nonce,
        public string $signature,
        public ?string $credentialIdHash = null,
    ) {}

    /** @return array{actorUserId: string, action: string, targetId: string, impersonationSessionId: ?string, method: string, credentialIdHash: ?string, verifiedAt: int, nonce: string, signature: string} */
    public function toArray(): array
    {
        return [
            'actorUserId'            => $this->actorUserId,
            'action'                 => $this->action,
            'targetId'               => $this->targetId,
            'impersonationSessionId' => $this->impersonationSessionId,
            'method'                 => $this->method,
            'credentialIdHash'       => $this->credentialIdHash,
            'verifiedAt'             => $this->verifiedAt,
            'nonce'                  => $this->nonce,
            'signature'              => $this->signature,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): ?self
    {
        if (!is_string($value['actorUserId'] ?? null)
            || !is_string($value['action'] ?? null)
            || !is_string($value['targetId'] ?? null)
            || !is_string($value['impersonationSessionId'] ?? null) && ($value['impersonationSessionId'] ?? null) !== null
            || !is_string($value['method'] ?? null)
            || !is_string($value['credentialIdHash'] ?? null) && ($value['credentialIdHash'] ?? null) !== null
            || !is_int($value['verifiedAt'] ?? null)
            || !is_string($value['nonce'] ?? null)
            || !is_string($value['signature'] ?? null)) {
            return null;
        }

        return new self(
            $value['actorUserId'],
            $value['action'],
            $value['targetId'],
            $value['impersonationSessionId'],
            $value['method'],
            $value['verifiedAt'],
            $value['nonce'],
            $value['signature'],
            $value['credentialIdHash'] ?? null,
        );
    }
}
