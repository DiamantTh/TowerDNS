<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Symfony\Component\Serializer\SerializerInterface;
use TowerDNS\Application\Exception\WebAuthnCredentialLimitException;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use Webauthn\CredentialRecord;

final readonly class DbalWebAuthnCredentialRepository implements WebAuthnCredentialRepositoryInterface
{
    public function __construct(
        private Connection          $connection,
        private SerializerInterface $serializer,
    ) {}

    #[\Override]
    public function findByUserId(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT credential_id, name, data, created_at, last_used_at, attachment
               FROM webauthn_credentials
              WHERE user_id = ?
           ORDER BY created_at ASC',
            [$userId],
        );

        $result = [];
        foreach ($rows as $row) {
            $source = $this->serializer->deserialize(
                (string) $row['data'],
                CredentialRecord::class,
                'json',
            );
            $result[] = [
                'credential_id'   => (string) $row['credential_id'],
                'name'            => (string) $row['name'],
                'created_at'      => (string) $row['created_at'],
                'last_used_at'    => isset($row['last_used_at']) ? (string) $row['last_used_at'] : null,
                'attachment'      => isset($row['attachment']) ? (string) $row['attachment'] : null,
                'aaguid'          => $source->aaguid->toRfc4122(),
                'transports'      => array_values(array_filter($source->transports, is_string(...))),
                'backup_eligible' => $source->backupEligible,
                'backup_state'    => $source->backupStatus,
                'source'          => $source,
            ];
        }

        return $result;
    }

    #[\Override]
    public function findByCredentialId(string $credentialId): ?CredentialRecord
    {
        $row = $this->connection->fetchAssociative(
            'SELECT data FROM webauthn_credentials WHERE credential_id = ?',
            [$credentialId],
        );

        if ($row === false) {
            return null;
        }

        return $this->serializer->deserialize(
            (string) $row['data'],
            CredentialRecord::class,
            'json',
        );
    }

    #[\Override]
    public function findByCredentialIdForUser(string $credentialId, string $userId): ?CredentialRecord
    {
        $row = $this->connection->fetchAssociative(
            'SELECT data FROM webauthn_credentials WHERE credential_id = ? AND user_id = ?',
            [$credentialId, $userId],
        );
        if ($row === false) {
            return null;
        }

        return $this->serializer->deserialize((string) $row['data'], CredentialRecord::class, 'json');
    }

    #[\Override]
    public function countAll(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM webauthn_credentials');
    }

    #[\Override]
    public function countByUserId(string $userId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = ?', [$userId]);
    }

    #[\Override]
    public function save(string $userId, string $name, CredentialRecord $source, ?string $attachment = null, int $maxCredentials = 10): void
    {
        $this->persist($userId, $name, $source, $attachment, $maxCredentials, 100);
    }

    #[\Override]
    public function saveDuringRecovery(string $userId, string $name, CredentialRecord $source, ?string $attachment, int $recoveryCeiling): void
    {
        // Keep every pre-recovery credential available until the replacement
        // is verified. The recovery service computes a cap of max(configured
        // cap, pre-recovery count + one), bounded by the technical maximum.
        $this->persist($userId, $name, $source, $attachment, max(1, min(101, $recoveryCeiling)), 101);
    }

    private function persist(string $userId, string $name, CredentialRecord $source, ?string $attachment, int $maxCredentials, int $technicalMax): void
    {
        $now  = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $data = $this->serializer->serialize($source, 'json');

        $this->connection->transactional(function (Connection $connection) use ($userId, $name, $source, $attachment, $maxCredentials, $technicalMax, $now, $data): void {
            if (!PlatformDetector::isSqlite($connection)) {
                $connection->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            }
            $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = ?', [$userId]);
            if ($count >= max(1, min($technicalMax, $maxCredentials))) {
                throw new WebAuthnCredentialLimitException('The configured WebAuthn credential limit has been reached.');
            }

            $connection->insert('webauthn_credentials', [
                'credential_id' => $source->publicKeyCredentialId,
                'user_id'       => $userId,
                'name'          => $name,
                'data'          => $data,
                'created_at'    => $now,
                'last_used_at'  => null,
                'attachment'    => in_array($attachment, ['platform', 'cross-platform'], true) ? $attachment : null,
            ]);
        });
    }

    #[\Override]
    public function updateAfterAuthentication(CredentialRecord $source): void
    {
        $data = $this->serializer->serialize($source, 'json');

        $this->connection->update(
            'webauthn_credentials',
            [
                'data'         => $data,
                'last_used_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            ],
            ['credential_id' => $source->publicKeyCredentialId],
        );
    }

    #[\Override]
    public function delete(string $credentialId, string $userId): void
    {
        $this->connection->delete(
            'webauthn_credentials',
            [
                'credential_id' => $credentialId,
                'user_id'       => $userId,
            ],
        );
    }
}
