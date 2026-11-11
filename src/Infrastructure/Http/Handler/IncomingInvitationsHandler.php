<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Services\AccountInvitationService;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\FormInput;

/** @psalm-api Registered by the application routes. */
final readonly class IncomingInvitationsHandler implements RequestHandlerInterface
{
    private const string PATH = '/profile/invitations';

    public function __construct(
        private TemplateRendererInterface $renderer,
        private TranslatorInterface $translator,
        private AccountInvitationService $invitations,
        private AuditLogService $audit,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(User::class);
        if (!$user instanceof User || !$user->active || $request->getAttribute('impersonation_session') !== null) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        if (!$guard instanceof CsrfGuardInterface) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }
        if ($request->getMethod() === 'POST') {
            $body   = FormInput::fromParsedBody($request->getParsedBody());
            $id     = filter_var($body['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $action = $body['action'] ?? '';
            if (!$guard->validateToken($body['csrf_token'] ?? '') || $id === false || !in_array($action, ['accept', 'decline'], true)) {
                return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
            }
            try {
                $invitation = $this->invitations->respond($user, $id, $action === 'accept');
            } catch (\DomainException) {
                return new RedirectResponse(self::PATH . '?unavailable=1', 303);
            }
            $event = $action === 'accept' ? 'account.invitation.accepted' : 'account.invitation.declined';
            $this->audit->record($request, $event, 'account_invitation', (string) $invitation->id, actorUserId: $user->id, accountId: $invitation->accountId);
            return new RedirectResponse($action === 'accept' ? '/accounts/' . $invitation->accountId : self::PATH, 303);
        }

        $query = FormInput::fromParsedBody($request->getQueryParams());
        $page  = max(1, min(10000, (int) ($query['page'] ?? '1')));
        $rows  = $this->invitations->incomingPage($user, ($page - 1) * 50);
        return new HtmlResponse($this->renderer->render('app::profile/invitations', [
            'user'           => $user,
            'csrfToken'      => $guard->generateToken(),
            'invitations'    => array_slice($rows, 0, 50),
            'invitationPage' => $page,
            'hasMore'        => $page < 10000 && count($rows) > 50,
            'error'          => isset($query['unavailable']) ? $this->translator->translate('accounts.error.invitation-unavailable') : null,
        ]), 200, ['Cache-Control' => 'private, no-store']);
    }
}
