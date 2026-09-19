<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\I18n\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Services\AccountInvitationRegistrationService;
use TowerDNS\Application\Services\AccountInvitationService;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Domain\Account\AccountInvitation;
use TowerDNS\Domain\Auth\User;

final readonly class AccountInvitationHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private AccountInvitationService $invitations,
        private AccountInvitationRegistrationService $registration,
        private AuditLogService $audit,
        private TranslatorInterface $translator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $token = (string) $request->getAttribute('token', '');
        /** @var User|null $user */
        $user = $request->getAttribute(User::class);
        if ($request->getMethod() === 'POST') {
            /** @var CsrfGuardInterface $guard */
            $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
            $body  = (array) ($request->getParsedBody() ?? []);
            if (!$guard instanceof CsrfGuardInterface || !$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
                return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
            }
            $action = (string) ($body['action'] ?? '');
            try {
                if ($action === 'register' && !$user instanceof User) {
                    $email    = (string) ($body['email'] ?? '');
                    $password = (string) ($body['password'] ?? '');
                    if ($password !== (string) ($body['password_confirm'] ?? '')) {
                        return $this->renderRegistration($request, $token, $this->translator->translate('accounts.error.password-mismatch'), $email);
                    }
                    $invitation = $this->invitations->preview($token);
                    $result     = $this->registration->register($token, $email, $password);
                    $this->audit->record($request, 'account.invitation.registered', 'account_invitation', (string) $invitation->id, actorUserId: $result->userId, accountId: $result->organizationAccountId);
                    return new RedirectResponse('/login?registered=1');
                }
                if ($action === 'accept') {
                    if (!$user instanceof User) {
                        return $this->renderRegistration($request, $token, null, '');
                    }
                    $invitation = $this->invitations->preview($token);
                    $this->invitations->accept($user, $token);
                    $this->audit->record($request, 'account.invitation.accepted', 'account_invitation', (string) $invitation->id, actorUserId: $user->id, accountId: $invitation->accountId);
                    return new RedirectResponse('/accounts/' . $invitation->accountId);
                }
                if ($action === 'decline') {
                    if (!$user instanceof User) {
                        return $this->renderRegistration($request, $token, null, '');
                    }
                    $invitation = $this->invitations->preview($token);
                    $this->invitations->decline($user, $token);
                    $this->audit->record($request, 'account.invitation.declined', 'account_invitation', (string) $invitation->id, actorUserId: $user->id, accountId: $invitation->accountId);
                    return new RedirectResponse('/accounts');
                }
            } catch (\Throwable $error) {
                if ($action === 'register' && !$user instanceof User) {
                    return $this->renderRegistration($request, $token, $this->registrationError($error), (string) ($body['email'] ?? ''));
                }
                return new HtmlResponse($this->translator->translate('accounts.error.invitation-unavailable'), 400);
            }
        }

        try {
            $invitation = $this->invitations->preview($token);
        } catch (\Throwable) {
            return new HtmlResponse($this->translator->translate('accounts.error.invitation-unavailable'), 404);
        }
        if (!$user instanceof User) {
            return $this->renderInvitation($request, $token, $invitation, true);
        }
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        return $this->renderInvitation($request, $token, $invitation, false, $guard instanceof CsrfGuardInterface ? $guard->generateToken() : '');
    }

    private function renderRegistration(ServerRequestInterface $request, string $token, ?string $error, string $email): ResponseInterface
    {
        try {
            $invitation = $this->invitations->preview($token);
        } catch (\Throwable) {
            return new HtmlResponse($this->translator->translate('accounts.error.invitation-unavailable'), 404);
        }
        return $this->renderInvitation($request, $token, $invitation, true, null, $error, $email);
    }

    private function renderInvitation(ServerRequestInterface $request, string $token, AccountInvitation $invitation, bool $registration, ?string $csrfToken = null, ?string $error = null, string $email = ''): ResponseInterface
    {
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        return new HtmlResponse($this->renderer->render($registration ? 'app::invitations/register' : 'app::invitations/accept', [
            'user'         => $request->getAttribute(User::class),
            'invitation'   => $this->safeInvitation($invitation),
            'token'        => $token,
            'csrfToken'    => $csrfToken ?? ($guard instanceof CsrfGuardInterface ? $guard->generateToken() : ''),
            'registration' => $registration,
            'error'        => $error,
            'email'        => $email !== '' ? $email : $invitation->email,
        ]));
    }

    private function registrationError(\Throwable $error): string
    {
        $message = strtolower($error->getMessage());
        return match (true) {
            str_contains($message, 'password')                                 => $this->translator->translate('auth.error.password-policy'),
            str_contains($message, 'already exists')                           => $this->translator->translate('accounts.error.invitation-existing-user'),
            str_contains($message, 'disabled')                                 => $this->translator->translate('accounts.error.invitation-user-disabled'),
            str_contains($message, 'email') && str_contains($message, 'match') => $this->translator->translate('accounts.error.invitation-email-mismatch'),
            default                                                            => $this->translator->translate('accounts.error.invitation-unavailable'),
        };
    }

    /** @return array{id:int,accountId:int,email:string,role:string,expiresAt:string} */
    private function safeInvitation(AccountInvitation $invitation): array
    {
        return [
            'id'        => $invitation->id,
            'accountId' => $invitation->accountId,
            'email'     => $invitation->email,
            'role'      => $invitation->role->value,
            'expiresAt' => $invitation->expiresAt,
        ];
    }
}
