<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\DTO\StepUpProof;
use TowerDNS\Application\Repository\StepUpProofNonceRepositoryInterface;
use TowerDNS\Application\Services\StepUpProofService;

final class StepUpProofServiceTest extends TestCase
{
    public function testProofIsValidOnlyForItsActorActionTargetAndImpersonationContext(): void
    {
        $service = new StepUpProofService(str_repeat('s', 32), $this->clock(1000), $this->nonceRepository());
        $proof   = $service->issue('actor-1', StepUpAction::IAM_USER_ROLES, 'user-2', 'switch-1', 'webauthn');

        self::assertTrue($service->isValid($proof, 'actor-1', StepUpAction::IAM_USER_ROLES, 'user-2', 'switch-1'));
        self::assertFalse($service->isValid($proof, 'actor-2', StepUpAction::IAM_USER_ROLES, 'user-2', 'switch-1'));
        self::assertFalse($service->isValid($proof, 'actor-1', StepUpAction::IAM_USER_STATUS, 'user-2', 'switch-1'));
        self::assertFalse($service->isValid($proof, 'actor-1', StepUpAction::IAM_USER_ROLES, 'user-3', 'switch-1'));
        self::assertFalse($service->isValid($proof, 'actor-1', StepUpAction::IAM_USER_ROLES, 'user-2', null));
    }

    public function testProofIsShortLivedAndTamperEvident(): void
    {
        $key     = str_repeat('s', 32);
        $proof   = new StepUpProofService($key, $this->clock(1000), $this->nonceRepository())->issue('actor', StepUpAction::IAM_ROLE_DELETE, 'role', null, 'totp');
        $expired = new StepUpProofService($key, $this->clock(1301), $this->nonceRepository());
        self::assertFalse($expired->isValid($proof, 'actor', StepUpAction::IAM_ROLE_DELETE, 'role', null));

        $tampered = new StepUpProof(
            $proof->actorUserId,
            $proof->action,
            'other-role',
            $proof->impersonationSessionId,
            $proof->method,
            $proof->verifiedAt,
            $proof->nonce,
            $proof->signature,
        );
        self::assertFalse(new StepUpProofService($key, $this->clock(1001), $this->nonceRepository())->isValid($tampered, 'actor', StepUpAction::IAM_ROLE_DELETE, 'other-role', null));
    }

    public function testProofNonceCanOnlyBeConsumedOnce(): void
    {
        $nonces = $this->createMock(StepUpProofNonceRepositoryInterface::class);
        $nonces->expects(self::exactly(2))->method('claim')->with(self::isType('string'), 1300)->willReturnOnConsecutiveCalls(true, false);
        $service = new StepUpProofService(str_repeat('s', 32), $this->clock(1000), $nonces);
        $proof   = $service->issue('actor', StepUpAction::IAM_ROLE_DELETE, 'role', null, 'totp');

        self::assertTrue($service->consumeOnce($proof, 'actor', StepUpAction::IAM_ROLE_DELETE, 'role', null));
        self::assertFalse($service->consumeOnce($proof, 'actor', StepUpAction::IAM_ROLE_DELETE, 'role', null));
    }

    private function nonceRepository(): StepUpProofNonceRepositoryInterface
    {
        $nonces = $this->createMock(StepUpProofNonceRepositoryInterface::class);
        $nonces->method('claim')->willReturn(true);

        return $nonces;
    }

    private function clock(int $timestamp): ClockInterface
    {
        return new readonly class ($timestamp) implements ClockInterface {
            public function __construct(private int $timestamp) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable()->setTimestamp($this->timestamp);
            }
        };
    }
}
