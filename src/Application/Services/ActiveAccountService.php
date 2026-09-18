<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\PersonalAccount;
use TowerDNS\Domain\Auth\User;

/** Resolves and validates an account selection independently of authorization. */
final readonly class ActiveAccountService
{
    public function __construct(private AccountRepositoryInterface $accounts, private ?UserLifecycleService $lifecycle = null) {}

    /** @return list<Account> */
    public function availableTo(User $user): array
    {
        $this->lifecycle?->ensurePersonalAccount($user->id);
        return array_values(array_filter($this->accounts->findByUserId($user->id), static fn(Account $account): bool => $account->isActive));
    }

    public function defaultFor(User $user): ?Account
    {
        $accounts = $this->availableTo($user);
        foreach ($accounts as $account) {
            if ($account->slug === PersonalAccount::slugFor($user->id)) {
                return $account;
            }
        }
        return $accounts[0] ?? null;
    }

    public function select(User $user, int $accountId): Account
    {
        foreach ($this->availableTo($user) as $account) {
            if ($account->id === $accountId) {
                return $account;
            }
        }
        throw new \DomainException('The selected account is not available to this user.');
    }

    public function resolve(User $user, ?int $accountId): ?Account
    {
        if ($accountId === null) {
            return null;
        }
        try {
            return $this->select($user, $accountId);
        } catch (\DomainException) {
            return null;
        }
    }
}
