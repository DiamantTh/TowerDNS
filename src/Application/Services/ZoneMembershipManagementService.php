<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\DTO\AuditContext;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\ZoneMembershipException;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Account\ZoneMembership;
use TowerDNS\Domain\Auth\User;

/**
 * Transport-independent managed-zone membership workflow.
 *
 * HTTP, CLI, and a future API all use the same account-scope authorization
 * and internal ManagedZone identity. Provider zone IDs never reach this API.
 */
final readonly class ZoneMembershipManagementService
{
    public function __construct(
        private ManagedZoneRepositoryInterface $managedZones,
        private ZoneMembershipRepositoryInterface $memberships,
        private UserRepositoryInterface $users,
        private PermissionService $permissions,
        private AuditLogService $audit,
    ) {}

    /**
     * @return list<ZoneMembership>
     * @throws AuthorizationException|ZoneMembershipException
     */
    public function list(User $actor, int $accountId, int $managedZoneId): array
    {
        $this->authorizeZoneAdministration($actor, $accountId, $managedZoneId);
        return $this->memberships->findByManagedZoneId($managedZoneId);
    }

    /** @throws AuthorizationException|ZoneMembershipException */
    public function grant(
        User $actor,
        int $accountId,
        int $managedZoneId,
        string $targetUserId,
        TeamRole $role,
        AuditContext $auditContext,
    ): void {
        $this->authorizeZoneAdministration($actor, $accountId, $managedZoneId);

        if (!$this->users->findById($targetUserId) instanceof User) {
            throw new ZoneMembershipException(ZoneMembershipException::TARGET_USER_NOT_FOUND);
        }

        $this->memberships->grant(
            $managedZoneId,
            $targetUserId,
            $role,
            new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            $actor->id,
        );
        $this->audit->recordWithContext(
            $auditContext->forManagedZone($accountId, $managedZoneId),
            'zone.member.grant',
            'user',
            $targetUserId,
            metadata: ['managed_zone_id' => $managedZoneId, 'role' => $role->value],
        );
    }

    /** @throws AuthorizationException|ZoneMembershipException */
    public function revoke(
        User $actor,
        int $accountId,
        int $managedZoneId,
        string $targetUserId,
        AuditContext $auditContext,
    ): void {
        $this->authorizeZoneAdministration($actor, $accountId, $managedZoneId);

        if (!$this->memberships->findMembership($managedZoneId, $targetUserId) instanceof ZoneMembership) {
            throw new ZoneMembershipException(ZoneMembershipException::MEMBERSHIP_NOT_FOUND);
        }

        $this->memberships->revoke($managedZoneId, $targetUserId);
        $this->audit->recordWithContext(
            $auditContext->forManagedZone($accountId, $managedZoneId),
            'zone.member.revoke',
            'user',
            $targetUserId,
            metadata: ['managed_zone_id' => $managedZoneId],
        );
    }

    /** @throws AuthorizationException|ZoneMembershipException */
    private function authorizeZoneAdministration(User $actor, int $accountId, int $managedZoneId): void
    {
        $this->permissions->assertCanManageMembers($accountId, $actor);
        if (!$this->managedZones->findByIdForAccount($managedZoneId, $accountId) instanceof \TowerDNS\Domain\Account\ManagedZone) {
            throw new ZoneMembershipException(ZoneMembershipException::MANAGED_ZONE_NOT_FOUND);
        }
    }
}
