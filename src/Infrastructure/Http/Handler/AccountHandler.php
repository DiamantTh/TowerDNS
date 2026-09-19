<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\I18n\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\AccountInvitationService;
use TowerDNS\Application\Services\AccountManagementService;
use TowerDNS\Application\Services\AccountMembershipManagementService;
use TowerDNS\Application\Services\AccountOwnershipService;
use TowerDNS\Application\Services\AccountResourceLimitManagementService;
use TowerDNS\Application\Services\AccountResourceUsageService;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AccountKind;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\ActiveAccountContext;

/**
 * Account management: list, create, edit, delete, manage members.
 *
 * Routes handled (all behind RequireAuthMiddleware):
 *   GET  /accounts               → list all accounts for the current user
 *   POST /accounts               → create a new account
 *   GET  /accounts/{id}          → edit form
 *   POST /accounts/{id}          → update name / deactivate
 *   GET  /accounts/{id}/members  → membership list
 *   POST /accounts/{id}/members  → invite or remove a member
 */
final readonly class AccountHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface            $renderer,
        private AccountRepositoryInterface           $accounts,
        private PermissionService                    $permissions,
        private AuditLogService                      $audit,
        private AccountMembershipManagementService   $memberships,
        private AccountOwnershipService              $ownership,
        private AccountManagementService             $accountManagement,
        private TranslatorInterface                  $translator,
        private UserRepositoryInterface             $users,
        private AccountResourceUsageService          $resourceUsage,
        private AccountResourceLimitManagementService $resourceLimits,
        private AccountInvitationService            $invitations,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/ownership')) {
            return $this->handleOwnershipPost($request);
        }

        // /accounts/{id}/members
        if (str_ends_with($path, '/members')) {
            return $request->getMethod() === 'POST'
                ? $this->handleMembersPost($request)
                : $this->handleMembersGet($request);
        }

        $id = $request->getAttribute('id');

        // /accounts/{id}
        if ($id !== null) {
            return $request->getMethod() === 'POST'
                ? $this->handleEditPost($request, (int) $id)
                : $this->handleEditGet($request, (int) $id);
        }

        // /accounts
        return $request->getMethod() === 'POST'
            ? $this->handleListPost($request)
            : $this->handleListGet($request);
    }

    // ── GET /accounts ─────────────────────────────────────────────────────────

    private function handleListGet(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        $query           = $request->getQueryParams();
        $search          = is_string($query['q'] ?? null) ? trim($query['q']) : null;
        $type            = AccountKind::tryFrom((string) ($query['type'] ?? ''));
        $role            = TeamRole::tryFrom((string) ($query['role'] ?? ''));
        $status          = in_array(($query['status'] ?? 'all'), ['active', 'inactive'], true) ? (string) $query['status'] : 'all';
        $active          = $status === 'active' ? true : ($status === 'inactive' ? false : null);
        $page            = max(1, (int) ($query['page'] ?? 1));
        $pageSize        = 50;
        $loaded          = $this->accounts->findByUserId($user->id, $search, $type, $pageSize + 1, ($page - 1) * $pageSize, true, $role, $active);
        $hasNext         = count($loaded) > $pageSize;
        $accounts        = array_map(static fn(Account $account): array => ['id' => $account->id, 'name' => $account->name, 'kind' => $account->kind()->value, 'isActive' => $account->isActive], array_slice($loaded, 0, $pageSize));
        $flashError      = $query['error'] ?? null;
        $activeContext   = $request->getAttribute(ActiveAccountContext::class);
        $activeAccountId = $activeContext instanceof ActiveAccountContext ? $activeContext->account?->id : null;

        return new HtmlResponse(
            $this->renderer->render('app::accounts/list', [
                'user'            => $user,
                'accounts'        => $accounts,
                'search'          => $search ?? '',
                'type'            => $type instanceof AccountKind ? $type->value : 'all',
                'role'            => $role instanceof TeamRole ? $role->value : 'all',
                'status'          => $status,
                'roles'           => TeamRole::cases(),
                'page'            => $page,
                'hasNext'         => $hasNext,
                'csrfToken'       => $csrfToken,
                'activeAccountId' => $activeAccountId,
                'error'           => is_string($flashError) ? $flashError : null,
            ]),
        );
    }

    // ── POST /accounts ────────────────────────────────────────────────────────

    private function handleListPost(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }

        $name = (string) ($body['name'] ?? '');
        $slug = (string) ($body['slug'] ?? '');

        try {
            $account = $this->accountManagement->create($user, $name, $slug);
            $this->audit->recordAccountCreated($request, $user->id, $account->id, $account->name, $account->slug);
        } catch (\Throwable) {
            return $this->errorRedirect('/accounts');
        }

        return new RedirectResponse('/accounts');
    }

    // ── GET /accounts/{id} ────────────────────────────────────────────────────

    private function handleEditGet(ServerRequestInterface $request, int $accountId): ResponseInterface
    {
        /** @var User $user */
        $user    = $request->getAttribute(User::class);
        $account = $this->accounts->findById($accountId);

        if (!$account instanceof Account) {
            return new HtmlResponse($this->translator->translate('http.error.not-found'), 404);
        }

        try {
            $this->permissions->assertCanManageAccount($accountId, $user);
        } catch (AuthorizationException) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        $flashError = $request->getQueryParams()['error'] ?? null;

        return new HtmlResponse(
            $this->renderer->render('app::accounts/edit', [
                'user'      => $user,
                'account'   => $account,
                'csrfToken' => $csrfToken,
                'usage'     => $this->resourceUsage->forUser($user, $accountId),
                'error'     => is_string($flashError) ? $flashError : null,
            ]),
        );
    }

    // ── POST /accounts/{id} ───────────────────────────────────────────────────

    private function handleEditPost(ServerRequestInterface $request, int $accountId): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }

        $action = (string) ($body['action'] ?? 'rename');

        if ($action === 'deactivate') {
            try {
                $this->accountManagement->deactivate($user, $accountId);
                $this->audit->recordAccountDeactivated($request, $user->id, $accountId);
            } catch (\Throwable $error) {
                return $this->errorRedirect('/accounts/' . $accountId, $error);
            }
            $session = $request->getAttribute(SessionInterface::class);
            if ($session instanceof SessionInterface && (int) $session->get('active_account_id', 0) === $accountId) {
                $session->unset('active_account_id');
            }
            return new RedirectResponse('/accounts');
        }

        if ($action === 'activate') {
            try {
                $this->accountManagement->activate($user, $accountId);
                $this->audit->record($request, 'account.activated', 'account', (string) $accountId, actorUserId: $user->id, accountId: $accountId);
            } catch (\Throwable $error) {
                return $this->errorRedirect('/accounts/' . $accountId, $error);
            }
            return new RedirectResponse('/accounts/' . $accountId);
        }

        if ($action === 'limits') {
            $parseLimit = static function (mixed $value): ?int {
                $value = trim((string) $value);
                if ($value === '') {
                    return null;
                }
                $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($parsed === false) {
                    throw new \DomainException('Resource limits are invalid.');
                }
                return $parsed;
            };
            try {
                $zones     = $parseLimit($body['max_zones'] ?? '');
                $members   = $parseLimit($body['max_members'] ?? '');
                $providers = $parseLimit($body['max_provider_accounts'] ?? '');
            } catch (\DomainException $error) {
                return $this->errorRedirect('/accounts/' . $accountId, $error);
            }
            try {
                $this->resourceLimits->update($user, $accountId, $zones, $members, $providers);
                $this->audit->record($request, 'account.limits.updated', 'account', (string) $accountId, actorUserId: $user->id, accountId: $accountId);
            } catch (\Throwable $error) {
                return $this->errorRedirect('/accounts/' . $accountId, $error);
            }
            return new RedirectResponse('/accounts/' . $accountId);
        }

        if ($action === 'delete') {
            try {
                $this->accountManagement->delete($user, $accountId);
                $this->audit->record($request, 'account.deleted', 'account', (string) $accountId, actorUserId: $user->id, accountId: $accountId);
            } catch (\Throwable $error) {
                return $this->errorRedirect('/accounts/' . $accountId, $error);
            }
            return new RedirectResponse('/accounts');
        }

        $name              = (string) ($body['name'] ?? '');
        $customerNumber    = isset($body['customer_number']) ? trim((string) $body['customer_number']) : null;
        $externalReference = isset($body['external_reference']) ? trim((string) $body['external_reference']) : null;

        try {
            $this->accountManagement->updateOrganizationDetails($user, $accountId, $name, $customerNumber, $externalReference);
            $this->audit->recordAccountRenamed($request, $user->id, $accountId, trim($name));
        } catch (\Throwable $error) {
            return $this->errorRedirect('/accounts/' . $accountId, $error);
        }

        return new RedirectResponse('/accounts/' . $accountId);
    }

    // ── GET /accounts/{id}/members ────────────────────────────────────────────

    private function handleMembersGet(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user      = $request->getAttribute(User::class);
        $accountId = (int) $request->getAttribute('id', 0);
        $account   = $this->accounts->findById($accountId);

        if (!$account instanceof Account) {
            return new HtmlResponse($this->translator->translate('http.error.not-found'), 404);
        }

        try {
            $this->permissions->assertCanManageMembers($accountId, $user);
        } catch (AuthorizationException) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        $members = array_map(function (\TowerDNS\Domain\Account\AccountMembership $membership): array {
            $member = $this->users->findByIdForAdministration($membership->userId);
            return ['id' => $membership->id, 'userId' => $membership->userId, 'email' => $member instanceof User ? $member->email : $membership->userId, 'displayName' => $member instanceof User ? $member->displayName : null, 'role' => $membership->role->value, 'createdAt' => $membership->createdAt];
        }, $this->accounts->findMemberships($accountId));
        $invitations = array_map(static fn(\TowerDNS\Domain\Account\AccountInvitation $invitation): array => [
            'id'        => $invitation->id,
            'email'     => $invitation->email,
            'role'      => $invitation->role->value,
            'createdAt' => $invitation->createdAt,
            'expiresAt' => $invitation->expiresAt,
            'status'    => $invitation->status()->value,
        ], $this->invitations->listForAccount($user, $accountId));
        $flashError = $request->getQueryParams()['error'] ?? null;

        return new HtmlResponse(
            $this->renderer->render('app::accounts/members', [
                'user'        => $user,
                'account'     => $account,
                'members'     => $members,
                'invitations' => $invitations,
                'roles'       => TeamRole::cases(),
                'csrfToken'   => $csrfToken,
                'error'       => is_string($flashError) ? $flashError : null,
            ]),
        );
    }

    // ── POST /accounts/{id}/members ───────────────────────────────────────────

    private function handleMembersPost(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $actor */
        $actor     = $request->getAttribute('actor_user') ?? $request->getAttribute(User::class);
        $accountId = (int) $request->getAttribute('id', 0);

        if (!$actor instanceof User) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }

        try {
            $this->permissions->assertCanManageMembers($accountId, $actor);
        } catch (AuthorizationException) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }

        $action       = (string) ($body['action'] ?? '');
        $targetUserId = trim((string) ($body['user_id'] ?? ''));
        $base         = '/accounts/' . $accountId . '/members';

        if ($action === 'remove') {
            if ($targetUserId === '') {
                return new RedirectResponse($base . '?error=' . rawurlencode($this->translator->translate('accounts.error.user-id-required')));
            }
            try {
                $this->memberships->revoke($actor, $accountId, $targetUserId);
                $this->audit->recordMemberRemoved($request, $actor->id, $accountId, $targetUserId);
            } catch (\Throwable $error) {
                return $this->errorRedirect($base, $error);
            }
            return new RedirectResponse($base);
        }

        if ($action === 'invite') {
            $roleVal = trim((string) ($body['role'] ?? ''));
            $role    = TeamRole::tryFrom($roleVal);
            $email   = strtolower(trim((string) ($body['email'] ?? '')));
            if ($email === '' && $targetUserId !== '') {
                $target = $this->users->findByIdForAdministration($targetUserId);
                $email  = $target instanceof User ? strtolower($target->email) : '';
            }

            if ($email === '' || $role === null) {
                return new RedirectResponse($base . '?error=' . rawurlencode($this->translator->translate('accounts.error.member-input-required')));
            }

            try {
                $origin  = $request->getUri()->getScheme() !== '' ? $request->getUri()->getScheme() . '://' . $request->getUri()->getHost() : '';
                $created = $this->invitations->create($actor, $accountId, $email, $role, $origin);
                $this->audit->record($request, 'account.invitation.created', 'account_invitation', (string) $created->invitation->id, actorUserId: $actor->id, accountId: $accountId, metadata: ['role' => $role->value, 'mail_delivered' => $created->mailDelivered]);
            } catch (\Throwable $error) {
                return $this->errorRedirect($base, $error);
            }

            return new RedirectResponse($base);
        }

        if ($action === 'revoke_invitation') {
            $invitationId = (int) ($body['invitation_id'] ?? 0);
            try {
                $this->invitations->revoke($actor, $invitationId);
                $this->audit->record($request, 'account.invitation.revoked', 'account_invitation', (string) $invitationId, actorUserId: $actor->id, accountId: $accountId);
            } catch (\Throwable $error) {
                return $this->errorRedirect($base, $error);
            }
            return new RedirectResponse($base);
        }

        if ($action === 'role') {
            $role = TeamRole::tryFrom(trim((string) ($body['role'] ?? '')));
            if ($targetUserId === '' || $role === null) {
                return new RedirectResponse($base . '?error=' . rawurlencode($this->translator->translate('accounts.error.member-input-required')));
            }
            try {
                $this->memberships->changeRole($actor, $accountId, $targetUserId, $role);
                $this->audit->record($request, 'account.member.role_changed', 'account_membership', $targetUserId, actorUserId: $actor->id, accountId: $accountId, metadata: ['role' => $role->value]);
            } catch (\Throwable $error) {
                return $this->errorRedirect($base, $error);
            }
            return new RedirectResponse($base);
        }

        return new RedirectResponse($base . '?error=' . rawurlencode($this->translator->translate('accounts.error.unknown-action')));
    }

    private function handleOwnershipPost(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $actor */
        $actor     = $request->getAttribute('actor_user') ?? $request->getAttribute(User::class);
        $accountId = (int) $request->getAttribute('id', 0);
        if (!$actor instanceof User) {
            return new RedirectResponse('/accounts?error=' . rawurlencode($this->translator->translate('http.error.invalid-request')));
        }
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $base = '/accounts/' . $accountId . '/members';
        if (!$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
            return new RedirectResponse($base . '?error=' . rawurlencode($this->translator->translate('http.error.invalid-request')));
        }
        $target = trim((string) ($body['user_id'] ?? ''));
        try {
            $this->ownership->transfer($actor, $accountId, $target);
            $this->audit->recordAccountOwnershipTransferred($request, $actor->id, $accountId, $target);
        } catch (\Throwable $error) {
            return $this->errorRedirect($base, $error);
        }
        return new RedirectResponse($base);
    }

    private function errorRedirect(string $path, ?\Throwable $error = null): RedirectResponse
    {
        $message = strtolower($error?->getMessage() ?? '');
        $key     = match (true) {
            str_contains($message, 'personal account')                                                      => 'accounts.error.personal-immutable',
            str_contains($message, 'still contains resources')                                              => 'accounts.error.resources-present',
            str_contains($message, 'additional members') || str_contains($message, 'other account members') => 'accounts.error.members-present',
            str_contains($message, 'already a member')                                                      => 'accounts.error.already-member',
            str_contains($message, 'target user not found') || str_contains($message, 'target user')        => 'accounts.error.user-not-found',
            str_contains($message, 'membership not found')                                                  => 'accounts.error.membership-not-found',
            str_contains($message, 'already pending')                                                       => 'accounts.error.invitation-pending',
            str_contains($message, 'invitation')                                                            => 'accounts.error.invitation-unavailable',
            str_contains($message, 'resource limits')                                                       => 'accounts.error.operation-failed',
            str_contains($message, 'ownership') || str_contains($message, 'owner')                          => 'accounts.error.ownership',
            str_contains($message, 'inactive')                                                              => 'accounts.error.inactive',
            default                                                                                         => 'accounts.error.operation-failed',
        };
        return new RedirectResponse($path . '?error=' . rawurlencode($this->translator->translate($key)));
    }
}
