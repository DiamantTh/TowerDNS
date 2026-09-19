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
use TowerDNS\Application\Services\AccountInvitationService;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Domain\Account\AccountInvitation;
use TowerDNS\Domain\Auth\User;

final readonly class AccountInvitationHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private AccountInvitationService $invitations,
        private AuditLogService $audit,
        private TranslatorInterface $translator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $token = (string) $request->getAttribute('token', '');
        /** @var User|null $user */
        $user = $request->getAttribute(User::class);
        if (!$user instanceof User) {
            return new RedirectResponse('/login');
        }

        if ($request->getMethod() === 'POST') {
            /** @var CsrfGuardInterface $guard */
            $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
            $body = (array) ($request->getParsedBody() ?? []);
            if (!$guard instanceof CsrfGuardInterface || !$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
                return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
            }
            $action = (string) ($body['action'] ?? '');
            try {
                if ($action === 'accept') {
                    $invitation = $this->invitations->preview($token);
                    $this->invitations->accept($user, $token);
                    $this->audit->record($request, 'account.invitation.accepted', 'account_invitation', (string) $invitation->id, actorUserId: $user->id, accountId: $invitation->accountId);
                    return new RedirectResponse('/accounts/' . $invitation->accountId);
                }
                if ($action === 'decline') {
                    $invitation = $this->invitations->preview($token);
                    $this->invitations->decline($user, $token);
                    $this->audit->record($request, 'account.invitation.declined', 'account_invitation', (string) $invitation->id, actorUserId: $user->id, accountId: $invitation->accountId);
                    return new RedirectResponse('/accounts');
                }
            } catch (\Throwable $error) {
                return new HtmlResponse($this->translator->translate('accounts.error.invitation-unavailable'), 400);
            }
        }

        try {
            $invitation = $this->invitations->preview($token);
        } catch (\Throwable) {
            return new HtmlResponse($this->translator->translate('accounts.error.invitation-unavailable'), 404);
        }
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        return new HtmlResponse($this->renderer->render('app::invitations/accept', [
            'user' => $user,
            'invitation' => $this->safeInvitation($invitation),
            'token' => $token,
            'csrfToken' => $guard instanceof CsrfGuardInterface ? $guard->generateToken() : '',
        ]));
    }

    /** @return array{id:int,accountId:int,email:string,role:string,expiresAt:string} */
    private function safeInvitation(AccountInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'accountId' => $invitation->accountId,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'expiresAt' => $invitation->expiresAt,
        ];
    }
}
