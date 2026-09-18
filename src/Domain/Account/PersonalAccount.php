<?php

declare(strict_types=1);

namespace TowerDNS\Domain\Account;

/** Stable, collision-resistant identity until a dedicated account-type column is introduced. */
final class PersonalAccount
{
    public static function slugFor(string $userId): string
    {
        return 'personal-' . strtolower($userId);
    }

    public static function kindOf(Account $account): AccountKind
    {
        return $account->slug === self::slugFor($account->ownerUserId)
            ? AccountKind::PERSONAL
            : AccountKind::ORGANIZATION;
    }
}
