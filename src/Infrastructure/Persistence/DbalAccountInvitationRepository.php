<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Repository\AccountInvitationRepositoryInterface;
use TowerDNS\Domain\Account\AccountInvitation;
use TowerDNS\Domain\Account\TeamRole;

final readonly class DbalAccountInvitationRepository implements AccountInvitationRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    public function create(int $accountId, string $email, ?string $userId, TeamRole $role, string $invitedBy, string $tokenHash, string $createdAt, string $expiresAt): int
    {
        $this->connection->insert('account_invitations', [
            'account_id' => $accountId,
            'email' => strtolower(trim($email)),
            'user_id' => $userId,
            'role' => $role->value,
            'invited_by' => $invitedBy,
            'token_hash' => $tokenHash,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
            'accepted_at' => null,
            'declined_at' => null,
            'revoked_at' => null,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function findById(int $id): ?AccountInvitation
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM account_invitations WHERE id = ?', [$id]);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByTokenHash(string $tokenHash): ?AccountInvitation
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM account_invitations WHERE token_hash = ?', [$tokenHash]);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findPendingByAccountAndEmail(int $accountId, string $email, string $now): ?AccountInvitation
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM account_invitations WHERE account_id = ? AND email = ? AND accepted_at IS NULL AND declined_at IS NULL AND revoked_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1',
            [$accountId, strtolower(trim($email)), $now],
        );
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByAccount(int $accountId): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM account_invitations WHERE account_id = ? ORDER BY created_at DESC', [$accountId]);
        return array_map($this->hydrate(...), $rows);
    }

    public function findPendingForUser(string $email, string $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM account_invitations WHERE email = ? AND accepted_at IS NULL AND declined_at IS NULL AND revoked_at IS NULL AND expires_at > ? ORDER BY created_at DESC',
            [strtolower(trim($email)), $now],
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function consume(int $id, string $userId, string $acceptedAt, string $now): bool
    {
        return $this->connection->executeStatement(
            'UPDATE account_invitations SET user_id = ?, accepted_at = ? WHERE id = ? AND accepted_at IS NULL AND declined_at IS NULL AND revoked_at IS NULL AND expires_at > ?',
            [$userId, $acceptedAt, $id, $now],
        ) === 1;
    }

    public function decline(int $id, string $declinedAt, string $now): bool
    {
        return $this->connection->executeStatement(
            'UPDATE account_invitations SET declined_at = ? WHERE id = ? AND accepted_at IS NULL AND declined_at IS NULL AND revoked_at IS NULL AND expires_at > ?',
            [$declinedAt, $id, $now],
        ) === 1;
    }

    public function revoke(int $id, string $revokedAt, string $now): bool
    {
        return $this->connection->executeStatement(
            'UPDATE account_invitations SET revoked_at = ? WHERE id = ? AND accepted_at IS NULL AND declined_at IS NULL AND revoked_at IS NULL AND expires_at > ?',
            [$revokedAt, $id, $now],
        ) === 1;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): AccountInvitation
    {
        return new AccountInvitation(
            id: (int) $row['id'],
            accountId: (int) $row['account_id'],
            email: (string) $row['email'],
            userId: isset($row['user_id']) && $row['user_id'] !== '' ? (string) $row['user_id'] : null,
            role: TeamRole::tryFrom((string) $row['role']) ?? TeamRole::VIEWER,
            invitedBy: (string) $row['invited_by'],
            tokenHash: (string) $row['token_hash'],
            createdAt: (string) $row['created_at'],
            expiresAt: (string) $row['expires_at'],
            acceptedAt: isset($row['accepted_at']) && $row['accepted_at'] !== '' ? (string) $row['accepted_at'] : null,
            declinedAt: isset($row['declined_at']) && $row['declined_at'] !== '' ? (string) $row['declined_at'] : null,
            revokedAt: isset($row['revoked_at']) && $row['revoked_at'] !== '' ? (string) $row['revoked_at'] : null,
        );
    }
}
