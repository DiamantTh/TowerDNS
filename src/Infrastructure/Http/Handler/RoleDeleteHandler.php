<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

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
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\IamAdministrationService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\StepUpRequestService;

/**
 * POST /roles/{id}/delete — Rolle löschen.
 *
 * Systemrollen können nicht gelöscht werden; der Repository wirft eine
 * DomainException, die hier in eine Fehlermeldung umgewandelt wird.
 * @psalm-api Constructed through runtime dependency injection or command/handler registration.
 */
final readonly class RoleDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private IamAdministrationService $iam,
        private TranslatorInterface     $translator,
        private StepUpRequestService    $stepUpRequests,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        /** @var array<string, mixed> $body */
        $body = (array) $request->getParsedBody();

        $raw   = $body['csrf_token'] ?? '';
        $token = is_array($raw) ? (string) ($raw[0] ?? '') : (string) $raw;

        if (!$guard->validateToken($token)) {
            return new RedirectResponse('/roles?error=' . rawurlencode($this->translator->translate('roles.error.invalid-csrf')));
        }

        $roleId   = (string) $request->getAttribute('id', '');
        $original = $request->getAttribute('actor_user');
        $switch   = $request->getAttribute('impersonation_session');
        $context  = AuditLogService::fromHttpRequest(
            $request,
            $original instanceof User ? $original->id : $currentUser->id,
            $currentUser->id,
            impersonationSessionId: $switch instanceof \TowerDNS\Domain\Account\AdminImpersonationSession ? $switch->id : null,
        );

        try {
            $proof = $this->stepUpRequests->consume($request, $currentUser->id, StepUpAction::IAM_ROLE_DELETE, $roleId);
            $this->iam->deleteRole($currentUser, $roleId, $context, $proof);
        } catch (StepUpRequiredException $required) {
            return $this->stepUpRequests->challenge($request, $currentUser->id, $required);
        } catch (AuthorizationException) {
            return new RedirectResponse('/roles?error=' . rawurlencode($this->translator->translate('http.error.forbidden')));
        } catch (\DomainException) {
            return new RedirectResponse('/roles?error=' . rawurlencode($this->translator->translate('roles.error.built-in-read-only')));
        } catch (\Throwable) {
            return new RedirectResponse('/roles?error=' . rawurlencode($this->translator->translate('roles.error.save-failed')));
        }

        return new RedirectResponse('/roles?success=' . rawurlencode($this->translator->translate('roles.success.deleted')));
    }
}
