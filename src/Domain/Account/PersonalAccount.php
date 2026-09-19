<?php

declare(strict_types=1);

namespace TowerDNS\Domain\Account;

/** Technical slug generator for the private account created with a user. */
final class PersonalAccount
{
    public static function slugFor(string $userId): string
    {
        return 'personal-' . strtolower($userId);
    }
}
