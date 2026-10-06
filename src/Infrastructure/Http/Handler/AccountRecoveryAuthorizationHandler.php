<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\StepUpRequiredException;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\IamAdministrationService;
use TowerDNS\Application\Services\MailService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\StepUpRequestService;

/** Issues a target-user recovery ticket after IAM and FIDO2 step-up checks. */
final readonly class AccountRecoveryAuthorizationHandler implements RequestHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private IamAdministrationService $iam,
        private StepUpRequestService $stepUp,
        private MailService $mail,
        private AuditLogService $audit,
        private TranslatorInterface $translator,
        private string $appBaseUrl,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User|null $actor */
        $actor    = $request->getAttribute(User::class);
        $targetId = (string) $request->getAttribute('id', '');
        if (!$actor instanceof User || $request->getAttribute('impersonation_session') !== null) {
            return new JsonResponse(['error' => $this->translator->translate('http.error.forbidden')], 403);
        }
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body  = \TowerDNS\Infrastructure\Http\FormInput::fromParsedBody($request->getParsedBody());
        if (!$guard->validateToken($body['csrf_token'] ?? '')) {
            return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode($this->translator->translate('http.error.invalid-request')));
        }
        $target = $this->users->findByIdForAdministration($targetId);
        if (!$target instanceof User || !$target->active) {
            return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode($this->translator->translate('users.error.not-found')));
        }

        try {
            $proof    = $this->stepUp->consume($request, $actor->id, StepUpAction::IAM_USER_ACCOUNT_RECOVERY, $targetId);
            $delivery = $this->iam->authorizeAccountRecovery(
                $actor,
                $targetId,
                AuditLogService::fromHttpRequest($request, $actor->id, $actor->id),
                $proof,
            );
        } catch (StepUpRequiredException $required) {
            return $this->stepUp->challenge($request, $actor->id, $required);
        } catch (AuthorizationException) {
            return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode($this->translator->translate('http.error.forbidden')));
        } catch (\Throwable) {
            return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode($this->translator->translate('recovery.authorization-failed')));
        }

        $link        = rtrim($this->appBaseUrl, '/') . '/account/recovery?ticket=' . rawurlencode($delivery['raw_ticket']);
        $escapedLink = '<a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
        try {
            $this->mail->send(
                $target->email,
                $this->translator->translate('recovery.email.subject'),
                strtr($this->translator->translate('recovery.email.html'), ['{link}' => $escapedLink]),
                strtr($this->translator->translate('recovery.email.text'), ['{link}' => $link]),
            );
        } catch (\Throwable) {
            $this->audit->record($request, 'security.account_recovery.delivery.failed', 'user', $targetId, $actor->id, metadata: ['recovery_id' => $delivery['recovery_id']]);
            return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode($this->translator->translate('recovery.delivery-failed')));
        }

        return new RedirectResponse('/users/' . rawurlencode($targetId) . '?success=' . rawurlencode($this->translator->translate('recovery.authorization-sent')));
    }
}
