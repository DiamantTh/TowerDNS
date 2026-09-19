<?php

declare(strict_types=1);

namespace TowerDNS\Domain\Account;

final readonly class AccountInvitation
{
    public function __construct(
        public int $id,
        public int $accountId,
        public string $email,
        public ?string $userId,
        public TeamRole $role,
        public string $invitedBy,
        public string $tokenHash,
        public string $createdAt,
        public string $expiresAt,
        public ?string $acceptedAt = null,
        public ?string $declinedAt = null,
        public ?string $revokedAt = null,
    ) {}

    public function status(?\DateTimeImmutable $now = null): AccountInvitationStatus
    {
        if ($this->acceptedAt !== null) {
            return AccountInvitationStatus::ACCEPTED;
        }
        if ($this->declinedAt !== null) {
            return AccountInvitationStatus::DECLINED;
        }
        if ($this->revokedAt !== null) {
            return AccountInvitationStatus::REVOKED;
        }
        $now ??= new \DateTimeImmutable();
        return $now >= new \DateTimeImmutable($this->expiresAt)
            ? AccountInvitationStatus::EXPIRED
            : AccountInvitationStatus::PENDING;
    }

    public function isPending(?\DateTimeImmutable $now = null): bool
    {
        return $this->status($now) === AccountInvitationStatus::PENDING;
    }
}
