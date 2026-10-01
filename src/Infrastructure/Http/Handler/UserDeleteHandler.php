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
 * POST /users/{id}/delete — löscht einen Benutzer.
 *
 * Verhindert Selbst-Löschung des angemeldeten Benutzers.
 */
final readonly class UserDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private IamAdministrationService $iam,
        private TranslatorInterface       $translator,
        private StepUpRequestService      $stepUpRequests,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);
        if (!$currentUser instanceof User) {
            return new RedirectResponse('/users?error=' . rawurlencode($this->t('http.error.forbidden')));
        }

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new RedirectResponse('/users?error=' . rawurlencode($this->t('http.error.invalid-request')));
        }

        $targetId = (string) $request->getAttribute('id', '');

        try {
            $original = $request->getAttribute('actor_user');
            $switch   = $request->getAttribute('impersonation_session');
            $context  = AuditLogService::fromHttpRequest(
                $request,
                $original instanceof User ? $original->id : $currentUser->id,
                $currentUser->id,
                impersonationSessionId: $switch instanceof \TowerDNS\Domain\Account\AdminImpersonationSession ? $switch->id : null,
            );
            $proof  = $this->stepUpRequests->consume($request, $currentUser->id, StepUpAction::IAM_USER_DELETE, $targetId);
            $target = $this->iam->deleteUser($currentUser, $targetId, $context, $proof);
        } catch (StepUpRequiredException $required) {
            return $this->stepUpRequests->challenge($request, $currentUser->id, $required);
        } catch (AuthorizationException) {
            if ($targetId === $currentUser->id) {
                return new RedirectResponse('/users?error=' . rawurlencode($this->t('users.error.self-delete-not-allowed')));
            }
            return new RedirectResponse('/users?error=' . rawurlencode($this->t('http.error.forbidden')));
        } catch (\Throwable) {
            return new RedirectResponse('/users?error=' . rawurlencode($this->t('users.error.delete-failed')));
        }

        return new RedirectResponse('/users?success=' . rawurlencode($this->t('users.success.deleted', ['email' => $target->email])));
    }

    /** @param array<string, string> $parameters */
    private function t(string $key, array $parameters = []): string
    {
        $replacements = [];
        foreach ($parameters as $name => $value) {
            $replacements['{' . $name . '}'] = $value;
        }

        return strtr($this->translator->translate($key), $replacements);
    }
}
