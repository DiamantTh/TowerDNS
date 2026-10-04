<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TowerDNS\Application\DTO\StepUpProof;
use TowerDNS\Application\Exception\StepUpRequiredException;
use TowerDNS\Application\Services\StepUpProofService;
use TowerDNS\Domain\Account\AdminImpersonationSession;

/** HTTP adapter for consuming or starting session-bound step-up challenges.
 * @psalm-api Constructed through runtime dependency injection or command/handler registration.
 */
final readonly class StepUpRequestService
{
    public function __construct(
        private SessionSecurity $sessionSecurity,
        private StepUpProofService $proofs,
    ) {}

    public function consume(ServerRequestInterface $request, string $actorUserId, string $action, string $targetId): ?StepUpProof
    {
        $session = $request->getAttribute(SessionInterface::class);
        if (!$session instanceof SessionInterface) {
            return null;
        }

        $switch   = $request->getAttribute('impersonation_session');
        $switchId = $switch instanceof AdminImpersonationSession ? $switch->id : null;

        return $this->sessionSecurity->consumeStepUpProof($session, $actorUserId, $action, $targetId, $switchId, $this->proofs);
    }

    public function challenge(ServerRequestInterface $request, string $actorUserId, StepUpRequiredException $required): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        if (!$session instanceof SessionInterface) {
            return new RedirectResponse('/login');
        }

        $switch   = $request->getAttribute('impersonation_session');
        $switchId = $switch instanceof AdminImpersonationSession ? $switch->id : null;
        $this->sessionSecurity->beginStepUp($session, $actorUserId, $required->action, $required->targetId, $switchId);

        return new RedirectResponse('/security/step-up');
    }

    public function hasProof(ServerRequestInterface $request, string $actorUserId, string $action, string $targetId): bool
    {
        $session = $request->getAttribute(SessionInterface::class);
        if (!$session instanceof SessionInterface) {
            return false;
        }
        $switch   = $request->getAttribute('impersonation_session');
        $switchId = $switch instanceof AdminImpersonationSession ? $switch->id : null;
        $proof    = $this->sessionSecurity->availableStepUpProof($session, $this->proofs);

        return $proof instanceof StepUpProof
            && $proof->actorUserId            === $actorUserId
            && $proof->action                 === $action
            && $proof->targetId               === $targetId
            && $proof->impersonationSessionId === $switchId;
    }

    public function challengeAction(ServerRequestInterface $request, string $actorUserId, string $action, string $targetId, bool $json = false): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        if (!$session instanceof SessionInterface) {
            return new RedirectResponse('/login');
        }
        $switch   = $request->getAttribute('impersonation_session');
        $switchId = $switch instanceof AdminImpersonationSession ? $switch->id : null;
        $this->sessionSecurity->beginStepUp($session, $actorUserId, $action, $targetId, $switchId);

        return $json
            ? new JsonResponse(['stepUpRequired' => true, 'redirect' => '/security/step-up'], 428)
            : new RedirectResponse('/security/step-up');
    }
}
