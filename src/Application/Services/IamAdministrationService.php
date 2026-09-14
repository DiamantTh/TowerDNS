<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Repository\UserRepositoryInterface;

final readonly class IamAdministrationService
{
    public function __construct(private UserRepositoryInterface $users) {}

    public function assertCanDelete(string $userId): void
    {
        $user = $this->users->findById($userId);
        if ($user instanceof \TowerDNS\Domain\Auth\User && in_array('superadmin', array_map(static fn(\TowerDNS\Domain\Auth\Role $role): string => $role->id, $user->roles), true) && $this->users->countActiveUsersWithRole('superadmin') <= 1) {
            throw new \DomainException('The last active superadmin cannot be deleted.');
        }
    }

    /** @param list<string> $roleIds */
    public function assertCanSyncRoles(string $userId, array $roleIds): void
    {
        $user = $this->users->findById($userId);
        if ($user instanceof \TowerDNS\Domain\Auth\User && in_array('superadmin', array_map(static fn(\TowerDNS\Domain\Auth\Role $role): string => $role->id, $user->roles), true) && !in_array('superadmin', $roleIds, true) && $this->users->countActiveUsersWithRole('superadmin') <= 1) {
            throw new \DomainException('The last active superadmin must retain the superadmin role.');
        }
    }
}
