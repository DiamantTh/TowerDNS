<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Uid\Uuid;
use TowerDNS\Application\Contracts\TransactionRunnerInterface;
use TowerDNS\Application\DTO\AuditContext;
use TowerDNS\Application\Repository\AccountRecoveryRepositoryInterface;
use TowerDNS\Application\Repository\PasswordResetTokenRepositoryInterface;
use TowerDNS\Application\Repository\TotpCredentialRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Domain\Auth\PasswordResetMethod;
use TowerDNS\Domain\Auth\PasswordResetToken;
use TowerDNS\Domain\Auth\User;
use Webauthn\CredentialRecord;

/** Password-independent recovery lifecycle; authorization remains in IAM. */
final readonly class AccountRecoveryService implements AccountRecoveryIssuerInterface
{
    public const int TICKET_TTL_SECONDS  = 86400;
    public const int SESSION_TTL_SECONDS = 900;

    public function __construct(
        private AccountRecoveryRepositoryInterface $recoveries,
        private PasswordResetTokenRepositoryInterface $tickets,
        private WebAuthnCredentialRepositoryInterface $webAuthn,
        private TotpCredentialRepositoryInterface $totp,
        private TransactionRunnerInterface $transactions,
        private AuditLogService $audit,
        private ClockInterface $clock,
    ) {}

    /** @return array{recovery_id:string,raw_ticket:string,expires_at:string} */
    #[\Override]
    public function authorize(User $target, string $actorId, AuditContext $context): array
    {
        $now        = $this->clock->now();
        $createdAt  = $now->format('Y-m-d H:i:s');
        $expiresAt  = $now->modify('+' . self::TICKET_TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s');
        $recoveryId = Uuid::v4()->toRfc4122();
        $rawTicket  = bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $rawTicket);

        $previousRecoveries = $this->recoveries->openForUser($target->id);
        $removedEnrollments = 0;
        foreach ($previousRecoveries as $previousRecovery) {
            $removedEnrollments += $this->removeRecoveryEnrolledCredentials($previousRecovery['id'], $target->id);
        }
        $revokedCount = $this->recoveries->revokeOpenForUser($target->id, $createdAt);
        $this->tickets->create($target->id, $tokenHash, $expiresAt, PasswordResetMethod::RECOVERY_CODE);
        $ticket = $this->tickets->findByHash($tokenHash);
        if (!$ticket instanceof PasswordResetToken) {
            throw new \RuntimeException('Recovery ticket persistence failed.');
        }

        $this->recoveries->create($recoveryId, $target->id, $actorId, $ticket->id, $createdAt, $expiresAt);
        foreach ($this->webAuthn->findByUserId($target->id) as $credential) {
            $this->recoveries->addCredentialSnapshot($recoveryId, 'webauthn', hash('sha256', $credential['source']->publicKeyCredentialId), true, $createdAt);
        }
        foreach ($this->totp->findByUserId($target->id) as $credential) {
            $this->recoveries->addCredentialSnapshot($recoveryId, 'totp', hash('sha256', $credential['id']), true, $createdAt);
        }
        $this->recoveries->invalidateNormalSessions($target->id);

        $this->audit->recordWithContext($context, 'security.account_recovery.authorized', 'user', $target->id, null, ['recovery_id' => $recoveryId, 'pre_recovery_webauthn_count' => $this->webAuthn->countByUserId($target->id), 'pre_recovery_totp_count' => $this->totp->countByUserId($target->id)]);
        $this->audit->recordWithContext($context, 'security.account_recovery.ticket.issued', 'user', $target->id, null, ['expires_at' => $expiresAt]);
        if ($revokedCount > 0) {
            $this->audit->recordWithContext($context, 'security.account_recovery.authorization.revoked', 'user', $target->id, ['open_authorizations' => $revokedCount], ['open_authorizations' => 0]);
        }
        if ($removedEnrollments > 0) {
            $this->audit->recordWithContext($context, 'security.account_recovery.webauthn.enrollment.revoked', 'user', $target->id, null, null, ['count' => $removedEnrollments, 'reason' => 'superseded_recovery']);
        }

        return ['recovery_id' => $recoveryId, 'raw_ticket' => $rawTicket, 'expires_at' => $expiresAt];
    }

    /** @return array{id:string,user_id:string,authorized_by:?string,status:string,expires_at:string,session_expires_at:string}|null */
    public function redeem(string $rawTicket, string $sessionIdHash, ServerRequestInterface $request): ?array
    {
        if ($rawTicket === '' || preg_match('/^[a-f0-9]{64}$/D', $rawTicket) !== 1) {
            return null;
        }
        $ticket = $this->tickets->findByHash(hash('sha256', $rawTicket));
        if (!$ticket instanceof PasswordResetToken || $ticket->method !== PasswordResetMethod::RECOVERY_CODE) {
            return null;
        }

        $now      = $this->clock->now();
        $nowValue = $now->format('Y-m-d H:i:s');
        $result   = $this->transactions->run(function () use ($ticket, $now, $nowValue, $sessionIdHash): ?array {
            if (!$ticket->isValidAt($now)) {
                return null;
            }
            $recovery = $this->recoveries->findByTicketId($ticket->id);
            if ($recovery === null || $recovery['user_id'] !== $ticket->userId || $recovery['status'] !== 'authorized') {
                return null;
            }
            if (!$this->tickets->consumeIfValid($ticket->id, $nowValue)) {
                return null;
            }
            $sessionExpiresAt = $now->modify('+' . self::SESSION_TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s');
            if (!$this->recoveries->begin($recovery['id'], $ticket->userId, $ticket->id, $sessionIdHash, $nowValue, $sessionExpiresAt)) {
                throw new \RuntimeException('Recovery ticket was consumed without starting its recovery.');
            }
            return [
                'id'                 => $recovery['id'],
                'user_id'            => $ticket->userId,
                'authorized_by'      => $recovery['authorized_by'],
                'status'             => 'in_progress',
                'expires_at'         => $recovery['expires_at'],
                'session_expires_at' => $sessionExpiresAt,
            ];
        });

        if (!is_array($result)) {
            if ($ticket->isExpiredAt($now)) {
                $this->audit->record($request, 'security.account_recovery.ticket.expired', 'user', $ticket->userId, $ticket->userId);
            } else {
                $this->audit->record($request, 'security.account_recovery.ticket.replay', 'user', $ticket->userId, $ticket->userId);
            }
            return null;
        }

        $this->audit->record($request, 'security.account_recovery.ticket.redeemed', 'user', $ticket->userId, $ticket->userId, metadata: ['recovery_id' => $result['id']]);
        $this->audit->record($request, 'security.account_recovery.session.created', 'user', $ticket->userId, $ticket->userId, metadata: ['recovery_id' => $result['id'], 'ttl_seconds' => self::SESSION_TTL_SECONDS]);
        return $result;
    }

    /**
     * Expiry handling may persist terminal recovery state and revoke incomplete credentials.
     *
     * @phpstan-impure
     */
    public function isUserLocked(string $userId): bool
    {
        $this->expireDueForUser($userId);
        return $this->recoveries->isLocked($userId, $this->clock->now()->format('Y-m-d H:i:s'));
    }

    public function validateSession(string $userId, string $recoveryId, string $sessionIdHash, bool $expireOnFailure = true): bool
    {
        $recovery = $this->recoveries->findForSession($recoveryId, $userId, $sessionIdHash);
        $now      = $this->clock->now();
        if ($recovery === null || $recovery['status'] !== 'in_progress' || $recovery['session_expires_at'] === null) {
            return false;
        }
        if ($recovery['expires_at'] <= $now->format('Y-m-d H:i:s') || $recovery['session_expires_at'] <= $now->format('Y-m-d H:i:s')) {
            if ($expireOnFailure) {
                $this->expireRecovery($recoveryId, $userId, $recovery['authorized_by'], 'in_progress');
            }
            return false;
        }
        return true;
    }

    public function persistRecoveryWebAuthn(
        string $userId,
        string $recoveryId,
        string $sessionIdHash,
        string $label,
        CredentialRecord $credential,
        ?string $attachment,
        int $maxCredentials,
    ): void {
        $this->transactions->run(function () use ($userId, $recoveryId, $sessionIdHash, $label, $credential, $attachment, $maxCredentials): void {
            if (!$this->recoveries->lockForSession($recoveryId, $userId, $sessionIdHash, $this->clock->now()->format('Y-m-d H:i:s'))) {
                throw new \DomainException('The account recovery session has expired.');
            }
            if (!$this->validateSession($userId, $recoveryId, $sessionIdHash, false)) {
                throw new \DomainException('The account recovery session has expired.');
            }
            $this->webAuthn->saveDuringRecovery($userId, $label, $credential, $attachment, $this->webAuthnEnrollmentCeiling($recoveryId, $maxCredentials));
            $this->recoveries->recordNewWebAuthn($recoveryId, hash('sha256', $credential->publicKeyCredentialId), $this->clock->now()->format('Y-m-d H:i:s'));
        });
    }

    public function webAuthnEnrollmentCeiling(string $recoveryId, int $configuredMaxCredentials): int
    {
        $preRecoveryCount = count(array_filter(
            $this->recoveries->credentials($recoveryId),
            static fn(array $entry): bool => $entry['type'] === 'webauthn' && $entry['pre_recovery'],
        ));
        return min(101, max(max(1, min(100, $configuredMaxCredentials)), $preRecoveryCount + 1));
    }

    public function complete(string $userId, string $recoveryId, string $sessionIdHash, AuditContext $context): void
    {
        $this->transactions->run(function () use ($userId, $recoveryId, $sessionIdHash, $context): void {
            if (!$this->recoveries->lockForSession($recoveryId, $userId, $sessionIdHash, $this->clock->now()->format('Y-m-d H:i:s'))) {
                throw new \DomainException('The account recovery session has expired.');
            }
            if (!$this->validateSession($userId, $recoveryId, $sessionIdHash, false)) {
                throw new \DomainException('The account recovery session has expired.');
            }
            $records = $this->recoveries->credentials($recoveryId);
            $newIds  = [];
            foreach ($records as $record) {
                if ($record['type'] === 'webauthn' && !$record['pre_recovery']) {
                    $newIds[$record['credential_id_hash']] = true;
                }
            }
            $currentWebAuthn             = $this->webAuthn->findByUserId($userId);
            $verifiedNewCredentialExists = array_any(
                $currentWebAuthn,
                static fn(array $credential): bool => isset($newIds[hash('sha256', $credential['source']->publicKeyCredentialId)]),
            );
            if (!$verifiedNewCredentialExists) {
                throw new \DomainException('Recovery requires a fully verified new FIDO2 credential.');
            }

            $preWebAuthn = [];
            $preTotp     = [];
            foreach ($records as $record) {
                if (!$record['pre_recovery']) {
                    continue;
                }
                if ($record['type'] === 'webauthn') {
                    $preWebAuthn[$record['credential_id_hash']] = true;
                } elseif ($record['type'] === 'totp') {
                    $preTotp[$record['credential_id_hash']] = true;
                }
            }
            $removedWebAuthn = 0;
            foreach ($currentWebAuthn as $credential) {
                if (isset($preWebAuthn[hash('sha256', $credential['source']->publicKeyCredentialId)])) {
                    $this->webAuthn->delete($credential['source']->publicKeyCredentialId, $userId);
                    ++$removedWebAuthn;
                }
            }
            $removedTotp = 0;
            foreach ($this->totp->findByUserId($userId) as $credential) {
                if (isset($preTotp[hash('sha256', $credential['id'])])) {
                    $this->totp->delete($credential['id'], $userId);
                    ++$removedTotp;
                }
            }

            if (!$this->recoveries->complete($recoveryId, $this->clock->now()->format('Y-m-d H:i:s'))) {
                throw new \DomainException('The account recovery was already completed or invalidated.');
            }
            $this->audit->recordWithContext($context, 'security.account_recovery.pre_recovery_webauthn.revoked', 'user', $userId, ['count' => 0], ['count' => $removedWebAuthn], ['recovery_id' => $recoveryId]);
            $this->audit->recordWithContext($context, 'security.account_recovery.pre_recovery_totp.revoked', 'user', $userId, ['count' => 0], ['count' => $removedTotp], ['recovery_id' => $recoveryId]);
            $this->audit->recordWithContext($context, 'security.account_recovery.completed', 'user', $userId, null, ['new_webauthn_count' => count($newIds)], ['recovery_id' => $recoveryId]);
        });
    }

    public function abort(string $userId, string $recoveryId, string $sessionIdHash, AuditContext $context): void
    {
        $this->transactions->run(function () use ($userId, $recoveryId, $sessionIdHash, $context): void {
            if (!$this->recoveries->lockForSession($recoveryId, $userId, $sessionIdHash, $this->clock->now()->format('Y-m-d H:i:s'))) {
                throw new \DomainException('The account recovery session is no longer active.');
            }
            $recovery = $this->recoveries->findForSession($recoveryId, $userId, $sessionIdHash);
            if ($recovery === null || $recovery['status'] !== 'in_progress') {
                throw new \DomainException('The account recovery session is no longer active.');
            }
            if (!$this->recoveries->abort($recoveryId, 'aborted', $this->clock->now()->format('Y-m-d H:i:s'))) {
                throw new \DomainException('The account recovery session is no longer active.');
            }
            $removedEnrollments = $this->removeRecoveryEnrolledCredentials($recoveryId, $userId);
            if ($removedEnrollments > 0) {
                $this->audit->recordWithContext($context, 'security.account_recovery.webauthn.enrollment.revoked', 'user', $userId, null, null, ['count' => $removedEnrollments, 'reason' => 'aborted']);
            }
            $this->audit->recordWithContext($context, 'security.account_recovery.aborted', 'user', $userId, null, null, ['recovery_id' => $recoveryId]);
        });
    }

    private function expireDueForUser(string $userId): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        foreach ($this->recoveries->expiredForUser($userId, $now) as $recovery) {
            $this->expireRecovery($recovery['id'], $userId, $recovery['authorized_by'], $recovery['status']);
        }
    }

    private function expireRecovery(string $recoveryId, string $userId, ?string $authorizedBy, string $priorStatus): void
    {
        $this->transactions->run(function () use ($recoveryId, $userId, $authorizedBy, $priorStatus): void {
            if (!$this->recoveries->abort($recoveryId, 'expired', $this->clock->now()->format('Y-m-d H:i:s'))) {
                return;
            }
            $removedEnrollments = $this->removeRecoveryEnrolledCredentials($recoveryId, $userId);
            $context            = new AuditContext($authorizedBy, $userId);
            if ($removedEnrollments > 0) {
                $this->audit->recordWithContext($context, 'security.account_recovery.webauthn.enrollment.revoked', 'user', $userId, null, null, ['count' => $removedEnrollments, 'reason' => 'expired']);
            }
            $event = $priorStatus === 'in_progress'
                ? 'security.account_recovery.session.expired'
                : 'security.account_recovery.ticket.expired';
            $this->audit->recordWithContext($context, $event, 'user', $userId, null, null, ['recovery_id' => $recoveryId]);
        });
    }

    private function removeRecoveryEnrolledCredentials(string $recoveryId, string $userId): int
    {
        $newWebAuthn = [];
        foreach ($this->recoveries->credentials($recoveryId) as $record) {
            if ($record['type'] === 'webauthn' && !$record['pre_recovery']) {
                $newWebAuthn[$record['credential_id_hash']] = true;
            }
        }
        $removed = 0;
        foreach ($this->webAuthn->findByUserId($userId) as $credential) {
            if (isset($newWebAuthn[hash('sha256', $credential['source']->publicKeyCredentialId)])) {
                $this->webAuthn->delete($credential['source']->publicKeyCredentialId, $userId);
                ++$removed;
            }
        }
        return $removed;
    }
}
