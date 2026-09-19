<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use Symfony\Component\Uid\Uuid;
use TowerDNS\Application\Contracts\TransactionRunnerInterface;
use TowerDNS\Application\DTO\AccountInvitationRegistrationResult;
use TowerDNS\Application\Repository\AccountInvitationRepositoryInterface;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AccountKind;
use TowerDNS\Domain\Account\TeamRole;

/**
 * Creates a user only as part of a valid, single-use account invitation.
 *
 * The transaction includes the user, personal account, organization
 * membership and invitation consumption, so a failed registration cannot
 * leave a half-created identity behind.
 */
final readonly class AccountInvitationRegistrationService
{
    private const array ARGON2ID_OPTIONS = [
        'memory_cost' => 131072,
        'time_cost'   => 4,
        'threads'     => 4,
    ];

    public function __construct(
        private AccountInvitationService $invitations,
        private AccountInvitationRepositoryInterface $invitationRepository,
        private AccountRepositoryInterface $accounts,
        private UserRepositoryInterface $users,
        private UserLifecycleService $lifecycle,
        private ResourceLimitService $resourceLimits,
        private TransactionRunnerInterface $transactions,
        private PasswordPolicy $passwordPolicy,
    ) {}

    public function register(string $rawToken, string $email, string $password): AccountInvitationRegistrationResult
    {
        $invitation = $this->invitations->preview($rawToken);
        $email      = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254 || $email !== $invitation->email) {
            throw new \DomainException('The invitation email address does not match.');
        }

        $existing = $this->users->findByEmailForAdministration($email);
        if ($existing instanceof \TowerDNS\Domain\Auth\User) {
            throw new \DomainException($existing->active
                ? 'An account already exists for this email address. Sign in before accepting the invitation.'
                : 'The account for this email address is disabled.');
        }

        $this->passwordPolicy->assertValid($password);
        $account = $this->accounts->findById($invitation->accountId);
        if (!$account instanceof Account || !$account->isActive || $account->kind() !== AccountKind::ORGANIZATION) {
            throw new \DomainException('The invited organization is no longer available.');
        }
        if ($invitation->role === TeamRole::OWNER) {
            throw new \DomainException('Invalid invitation role.');
        }
        $this->resourceLimits->assertCanAddMember($account->id);

        $userId = Uuid::v4()->toRfc4122();
        $hash   = password_hash($password, PASSWORD_ARGON2ID, self::ARGON2ID_OPTIONS);

        return $this->transactions->run(function () use ($invitation, $account, $userId, $email, $hash): AccountInvitationRegistrationResult {
            $personal = $this->lifecycle->create($userId, $email, $hash);
            $now      = new \DateTimeImmutable()->format('Y-m-d H:i:s');
            $this->accounts->addMembership($account->id, $userId, $invitation->role, $now, $invitation->invitedBy);

            if (!$this->invitationRepository->consume($invitation->id, $userId, $now, $now)) {
                throw new \DomainException('The invitation is no longer available.');
            }

            return new AccountInvitationRegistrationResult($userId, $personal->id, $account->id);
        });
    }
}
