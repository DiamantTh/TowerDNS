<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionIdentifierAwareInterface;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\DTO\AuditContext;
use TowerDNS\Application\Services\AccountRecoveryService;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\SessionSecurity;

/**
 * Completion and cancellation endpoints inside the restricted recovery flow.
 * @psalm-api Constructed through runtime dependency injection or command/handler registration.
 */
final readonly class AccountRecoveryActionHandler implements RequestHandlerInterface
{
    public function __construct(private AccountRecoveryService $recoveries, private SessionSecurity $sessions, private TranslatorInterface $translator, private AuditLogService $audit) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        $user    = $request->getAttribute(User::class);
        $state   = $request->getAttribute('account_recovery');
        if (!$session instanceof SessionInterface || !$session instanceof SessionIdentifierAwareInterface || !$user instanceof User || !is_array($state)) {
            return new JsonResponse(['error' => $this->translator->translate('recovery.session-expired')], 410);
        }
        /** @var \Mezzio\Csrf\CsrfGuardInterface|null $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body  = \TowerDNS\Infrastructure\Http\FormInput::fromParsedBody($request->getParsedBody());
        if ($guard === null || !$guard->validateToken($body['csrf_token'] ?? '')) {
            return new JsonResponse(['error' => $this->translator->translate('http.error.invalid-request')], 400);
        }

        $recoveryId    = (string) ($state['recovery_id'] ?? '');
        $sessionIdHash = hash('sha256', $session->getId());
        $context       = new AuditContext($user->id, $user->id);
        try {
            if ($request->getUri()->getPath() === '/account/recovery/abort') {
                $this->recoveries->abort($user->id, $recoveryId, $sessionIdHash, $context);
                $this->sessions->endRecovery($session);
                return new JsonResponse(['ok' => true, 'redirect' => '/login?recovery=cancelled']);
            }
            $this->recoveries->complete($user->id, $recoveryId, $sessionIdHash, $context);
        } catch (\DomainException $exception) {
            $this->audit->record($request, 'security.account_recovery.failed', 'user', $user->id, $user->id, metadata: ['recovery_id' => $recoveryId, 'reason' => str_contains($exception->getMessage(), 'new FIDO2') ? 'new_fido2_required' : 'session_invalid']);
            $message = str_contains($exception->getMessage(), 'new FIDO2')
                ? $this->translator->translate('recovery.new-key-required')
                : $this->translator->translate('recovery.session-expired');
            return new JsonResponse(['error' => $message], 409);
        } catch (\Throwable) {
            $this->audit->record($request, 'security.account_recovery.failed', 'user', $user->id, $user->id, metadata: ['recovery_id' => $recoveryId, 'reason' => 'completion_error']);
            return new JsonResponse(['error' => $this->translator->translate('recovery.completion-failed')], 500);
        }

        $this->sessions->endRecovery($session);
        return new JsonResponse(['ok' => true, 'redirect' => '/login?recovery=complete']);
    }
}
