<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Symfony\Component\Serializer\SerializerInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use Webauthn\PublicKeyCredentialSource;

final readonly class DbalWebAuthnCredentialRepository implements WebAuthnCredentialRepositoryInterface
{
    public function __construct(
        private Connection          $connection,
        private SerializerInterface $serializer,
    ) {}

    public function findByUserId(string $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT credential_id, name, data, created_at, last_used_at
               FROM webauthn_credentials
              WHERE user_id = ?
           ORDER BY created_at ASC',
            [$userId],
        );

        $result = [];
        foreach ($rows as $row) {
            $source = $this->serializer->deserialize(
                (string) $row['data'],
                PublicKeyCredentialSource::class,
                'json',
            );
            $result[] = [
                'credential_id' => (string) $row['credential_id'],
                'name'          => (string) $row['name'],
                'created_at'    => (string) $row['created_at'],
                'last_used_at'  => isset($row['last_used_at']) ? (string) $row['last_used_at'] : null,
                'source'        => $source,
            ];
        }

        return $result;
    }

    public function findByCredentialId(string $credentialId): ?PublicKeyCredentialSource
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
            PublicKeyCredentialSource::class,
            'json',
        );
    }

    public function save(string $userId, string $name, PublicKeyCredentialSource $source): void
    {
        $now  = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $data = $this->serializer->serialize($source, 'json');

        $this->connection->insert('webauthn_credentials', [
            'credential_id' => $source->publicKeyCredentialId,
            'user_id'       => $userId,
            'name'          => $name,
            'data'          => $data,
            'created_at'    => $now,
            'last_used_at'  => null,
        ]);
    }

    public function updateAfterAuthentication(string $credentialId, int $counter): void
    {
        $source = $this->findByCredentialId($credentialId);
        if (!$source instanceof PublicKeyCredentialSource) {
            return;
        }

        // Update the counter in the stored source object.
        $source->counter = $counter;
        $data            = $this->serializer->serialize($source, 'json');

        $this->connection->update(
            'webauthn_credentials',
            [
                'data'         => $data,
                'last_used_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            ],
            ['credential_id' => $credentialId],
        );
    }

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
