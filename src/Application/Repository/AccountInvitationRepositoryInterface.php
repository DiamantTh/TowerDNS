<?php

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

use TowerDNS\Domain\Account\AccountInvitation;
use TowerDNS\Domain\Account\TeamRole;

interface AccountInvitationRepositoryInterface
{
    public function create(
        int $accountId,
        string $email,
        ?string $userId,
        TeamRole $role,
        string $invitedBy,
        string $tokenHash,
        string $createdAt,
        string $expiresAt,
    ): int;

    public function findById(int $id): ?AccountInvitation;

    public function findByTokenHash(string $tokenHash): ?AccountInvitation;

    public function findPendingByAccountAndEmail(int $accountId, string $email, string $now): ?AccountInvitation;

    /** @return list<AccountInvitation> */
    public function findByAccount(int $accountId): array;

    /** @return list<AccountInvitation> */
    public function findPendingForUser(string $email, string $now): array;

    public function consume(int $id, string $userId, string $acceptedAt, string $now): bool;

    public function decline(int $id, string $declinedAt, string $now): bool;

    public function revoke(int $id, string $revokedAt, string $now): bool;
}
