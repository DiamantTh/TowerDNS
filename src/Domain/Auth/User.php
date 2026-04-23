<?php

declare(strict_types=1);

namespace TowerDNS\Domain\Auth;

final class User
{
    /**
     * @param list<Role> $roles
     */
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly array $roles = []
    ) {
    }

    public function hasPermission(Permission $permission): bool
    {
        foreach ($this->roles as $role) {
            if ($role->has($permission)) {
                return true;
            }
        }

        return false;
    }
}
