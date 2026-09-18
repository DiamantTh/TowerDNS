<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Contracts\TransactionRunnerInterface;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\AccountResourceLimitsRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AccountResourceLimits;
use TowerDNS\Domain\Account\PersonalAccount;

/** One place for person creation and the account that owns personal resources. */
final readonly class UserLifecycleService
{
    public function __construct(
        private TransactionRunnerInterface $transactions,
        private UserRepositoryInterface $users,
        private AccountRepositoryInterface $accounts,
        private AccountResourceLimitsRepositoryInterface $limits,
        private ManagedZoneRepositoryInterface $zones,
        private ProviderAccountRepositoryInterface $providers,
    ) {}

    public function create(string $userId, string $email, string $passwordHash): Account
    {
        return $this->transactions->run(function () use ($userId, $email, $passwordHash): Account {
            $this->users->create($userId, $email, $passwordHash);
            return $this->createPersonalAccount($userId);
        });
    }

    public function ensurePersonalAccount(string $userId): Account
    {
        $existing = $this->accounts->findBySlug(PersonalAccount::slugFor($userId));
        if ($existing instanceof Account) {
            if ($existing->ownerUserId !== $userId) {
                throw new \DomainException('Personal account owner mismatch.');
            }
            return $existing;
        }
        return $this->transactions->run(function () use ($userId): Account {
            $account = $this->accounts->findBySlug(PersonalAccount::slugFor($userId));
            if ($account instanceof Account) {
                if ($account->ownerUserId !== $userId) {
                    throw new \DomainException('Personal account owner mismatch.');
                }
                return $account;
            }
            return $this->createPersonalAccount($userId);
        });
    }

    public function delete(string $userId): void
    {
        $this->transactions->run(function () use ($userId): void {
            $personal = $this->accounts->findBySlug(PersonalAccount::slugFor($userId));
            foreach ($this->accounts->findAll() as $account) {
                if ($account->ownerUserId === $userId && $account->id !== $personal?->id) {
                    throw new \DomainException('Transfer organization account ownership before deleting the user.');
                }
            }
            if ($personal instanceof Account) {
                if ($personal->ownerUserId !== $userId) {
                    throw new \DomainException('Personal account owner mismatch.');
                }
                foreach ($this->accounts->findMemberships($personal->id) as $membership) {
                    if ($membership->userId !== $userId) {
                        throw new \DomainException('Personal account has unexpected members.');
                    }
                }
                if ($this->zones->findByAccountId($personal->id) !== [] || $this->providers->findByAccountId($personal->id) !== []) {
                    throw new \DomainException('Personal account still contains resources.');
                }
                $this->accounts->delete($personal->id);
            }
            $this->users->delete($userId);
        });
    }

    private function createPersonalAccount(string $userId): Account
    {
        $id = $this->accounts->create('Personal', PersonalAccount::slugFor($userId), $userId, new \DateTimeImmutable()->format('Y-m-d H:i:s'));
        $this->limits->save(new AccountResourceLimits($id, null, null, null));
        return $this->accounts->findById($id) ?? throw new \RuntimeException('Personal account creation failed.');
    }
}
