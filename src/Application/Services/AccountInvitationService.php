<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\DTO\AccountInvitationCreationResult;
use TowerDNS\Application\Repository\AccountInvitationRepositoryInterface;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AccountInvitation;
use TowerDNS\Domain\Account\AccountKind;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\User;

final readonly class AccountInvitationService
{
    private const int EXPIRY_DAYS = 7;

    public function __construct(
        private AccountInvitationRepositoryInterface $invitations,
        private AccountRepositoryInterface $accounts,
        private UserRepositoryInterface $users,
        private PermissionService $permissions,
        private ResourceLimitService $resourceLimits,
        private MailService $mail,
    ) {}

    public function create(User $actor, int $accountId, string $email, TeamRole $role, string $baseUrl = ''): AccountInvitationCreationResult
    {
        $this->permissions->assertCanManageMembers($accountId, $actor);
        $account = $this->accountForInvitation($accountId);
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            throw new \DomainException('Invitation email is invalid.');
        }
        if ($role === TeamRole::OWNER) {
            throw new \DomainException('Account ownership can only be changed through ownership transfer.');
        }

        $target = $this->users->findByEmail($email);
        if ($target instanceof User && $this->accounts->findMembership($accountId, $target->id) !== null) {
            throw new \DomainException('User is already a member of this account.');
        }
        $now = new \DateTimeImmutable();
        $nowText = $now->format('Y-m-d H:i:s');
        if ($this->invitations->findPendingByAccountAndEmail($accountId, $email, $nowText) instanceof AccountInvitation) {
            throw new \DomainException('An invitation for this email is already pending.');
        }

        $this->resourceLimits->assertCanAddMember($accountId);
        $rawToken = bin2hex(random_bytes(32));
        $expires = $now->modify('+' . self::EXPIRY_DAYS . ' days');
        $id = $this->invitations->create(
            accountId: $accountId,
            email: $email,
            userId: $target instanceof User ? $target->id : null,
            role: $role,
            invitedBy: $actor->id,
            tokenHash: hash('sha256', $rawToken),
            createdAt: $nowText,
            expiresAt: $expires->format('Y-m-d H:i:s'),
        );
        $invitation = $this->invitations->findById($id);
        if (!$invitation instanceof AccountInvitation) {
            throw new \RuntimeException('Invitation persistence failed.');
        }

        $url = rtrim($baseUrl, '/') . '/invitations/' . rawurlencode($rawToken);
        $mailDelivered = true;
        try {
            $this->mail->send(
                $email,
                'TowerDNS account invitation',
                '<p>You have been invited to <strong>' . htmlspecialchars($account->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>.</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Review invitation</a></p><p>This link expires in ' . self::EXPIRY_DAYS . ' days.</p>',
                "You have been invited to {$account->name}. Review: {$url}\nThis link expires in " . self::EXPIRY_DAYS . ' days.',
            );
        } catch (\Throwable) {
            $mailDelivered = false;
        }

        return new AccountInvitationCreationResult($invitation, $mailDelivered);
    }

    public function accept(User $user, string $rawToken): void
    {
        $invitation = $this->pendingByToken($rawToken);
        if (strtolower($user->email) !== $invitation->email) {
            throw new \DomainException('This invitation belongs to another email address.');
        }
        $account = $this->accountForInvitation($invitation->accountId);
        if ($this->accounts->findMembership($account->id, $user->id) !== null) {
            throw new \DomainException('User is already a member of this account.');
        }
        $this->resourceLimits->assertCanAddMember($account->id);
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        try {
            $this->accounts->addMembership($account->id, $user->id, $invitation->role, $now, $invitation->invitedBy);
        } catch (\Throwable $error) {
            throw new \DomainException('Invitation could not be accepted.', 0, $error);
        }
        if (!$this->invitations->consume($invitation->id, $user->id, $now, $now)) {
            $this->accounts->removeMembership($account->id, $user->id);
            throw new \DomainException('Invitation is no longer available.');
        }
    }

    public function preview(string $rawToken): AccountInvitation
    {
        return $this->pendingByToken($rawToken);
    }

    public function decline(User $user, string $rawToken): void
    {
        $invitation = $this->pendingByToken($rawToken);
        if (strtolower($user->email) !== $invitation->email) {
            throw new \DomainException('This invitation belongs to another email address.');
        }
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        if (!$this->invitations->decline($invitation->id, $now, $now)) {
            throw new \DomainException('Invitation is no longer available.');
        }
    }

    public function revoke(User $actor, int $invitationId): void
    {
        $invitation = $this->invitations->findById($invitationId);
        if (!$invitation instanceof AccountInvitation) {
            throw new \DomainException('Invitation not found.');
        }
        $this->permissions->assertCanManageMembers($invitation->accountId, $actor);
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        if (!$this->invitations->revoke($invitationId, $now, $now)) {
            throw new \DomainException('Invitation is no longer available.');
        }
    }

    /** @return list<AccountInvitation> */
    public function listForAccount(User $user, int $accountId): array
    {
        $this->permissions->assertCanManageMembers($accountId, $user);
        return $this->invitations->findByAccount($accountId);
    }

    /** @return list<AccountInvitation> */
    public function listForUser(User $user): array
    {
        return $this->invitations->findPendingForUser($user->email, new \DateTimeImmutable()->format('Y-m-d H:i:s'));
    }

    private function pendingByToken(string $rawToken): AccountInvitation
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
            throw new \DomainException('Invitation is invalid or expired.');
        }
        $invitation = $this->invitations->findByTokenHash(hash('sha256', $rawToken));
        if (!$invitation instanceof AccountInvitation || !$invitation->isPending()) {
            throw new \DomainException('Invitation is invalid or expired.');
        }
        return $invitation;
    }

    private function accountForInvitation(int $accountId): Account
    {
        $account = $this->accounts->findById($accountId);
        if (!$account instanceof Account || !$account->isActive) {
            throw new \DomainException('Account not found or inactive.');
        }
        if ($account->kind() === AccountKind::PERSONAL) {
            throw new \DomainException('Personal accounts cannot have additional members.');
        }
        return $account;
    }
}
