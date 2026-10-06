<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionIdentifierAwareInterface;
use Mezzio\Session\SessionInterface;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\AccountRecoveryRepositoryInterface;
use TowerDNS\Application\Repository\PasswordResetTokenRepositoryInterface;
use TowerDNS\Application\Services\AccountRecoveryService;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Domain\Auth\PasswordResetMethod;
use TowerDNS\Domain\Auth\PasswordResetToken;
use TowerDNS\Infrastructure\Http\SessionSecurity;

/**
 * Public ticket landing and one-shot entry to a restricted recovery session.
 * @psalm-api Constructed through runtime dependency injection or command/handler registration.
 */
final readonly class AccountRecoveryHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private PasswordResetTokenRepositoryInterface $tickets,
        private AccountRecoveryRepositoryInterface $recoveries,
        private AccountRecoveryService $recoveryService,
        private SessionSecurity $sessions,
        private AuditLogService $audit,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        if (!$session instanceof SessionInterface) {
            return new HtmlResponse('Recovery session unavailable.', 500);
        }
        /** @var CsrfGuardInterface $guard */
        $guard             = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $isRecoverySession = is_array($request->getAttribute('account_recovery'));

        if ($request->getMethod() === 'GET') {
            $query       = $request->getQueryParams();
            $rawTicket   = trim((string) ($query['ticket'] ?? ''));
            $validTicket = $rawTicket !== '' && $this->ticketIsAvailable($rawTicket, $request);
            return new HtmlResponse($this->renderer->render('app::account/recovery', [
                'csrfToken'       => $guard->generateToken(),
                'ticket'          => $validTicket ? $rawTicket : '',
                'recoverySession' => $isRecoverySession,
                'expired'         => (string) ($query['expired'] ?? '')    === '1',
                'restricted'      => (string) ($query['restricted'] ?? '') === '1',
                'error'           => $validTicket || $isRecoverySession ? null : ($rawTicket !== '' ? $this->translator->translate('recovery.ticket-invalid') : null),
            ]));
        }

        if ($isRecoverySession) {
            return new HtmlResponse($this->translator->translate('recovery.ticket-invalid'), 409);
        }
        $body = \TowerDNS\Infrastructure\Http\FormInput::fromParsedBody($request->getParsedBody());
        if (!$guard->validateToken($body['csrf_token'] ?? '')) {
            return new HtmlResponse($this->renderer->render('app::account/recovery', [
                'csrfToken' => $guard->generateToken(), 'ticket' => '', 'error' => $this->translator->translate('http.error.invalid-request'),
            ]), 400);
        }
        $rawTicket = trim($body['ticket'] ?? '');
        if (!$session instanceof SessionIdentifierAwareInterface) {
            return new HtmlResponse('Recovery session unavailable.', 500);
        }

        // Rotate first, then bind the durable recovery row to the new server session ID.
        $session = $this->sessions->beginRecovery($session);
        if (!$session instanceof SessionIdentifierAwareInterface) {
            return new HtmlResponse('Recovery session unavailable.', 500);
        }
        $sessionIdHash = hash('sha256', $session->getId());
        $recovery      = $this->recoveryService->redeem($rawTicket, $sessionIdHash, $request);
        if ($recovery === null) {
            $this->sessions->endRecovery($session);
            return new RedirectResponse('/account/recovery?expired=1');
        }
        $session->set('account_recovery', [
            'user_id'         => $recovery['user_id'],
            'recovery_id'     => $recovery['id'],
            'session_id_hash' => $sessionIdHash,
            'purpose'         => 'credential_recovery',
        ]);
        return new RedirectResponse('/account/recovery');
    }

    private function ticketIsAvailable(string $rawTicket, ServerRequestInterface $request): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $rawTicket) !== 1) {
            return false;
        }
        $ticket = $this->tickets->findByHash(hash('sha256', $rawTicket));
        if (!$ticket instanceof PasswordResetToken || $ticket->method !== PasswordResetMethod::RECOVERY_CODE) {
            return false;
        }
        if ($ticket->isUsed()) {
            $this->audit->record($request, 'security.account_recovery.ticket.replay', 'user', $ticket->userId, $ticket->userId);
            return false;
        }
        if ($ticket->isExpiredAt($this->clock->now())) {
            $this->recoveryService->isUserLocked($ticket->userId);
            return false;
        }
        $recovery = $this->recoveries->findByTicketId($ticket->id);
        return $recovery !== null && $recovery['status'] === 'authorized';
    }
}
