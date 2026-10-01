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
use Symfony\Component\Uid\Uuid;
use TowerDNS\Application\DTO\AuditContext;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\StepUpRequiredException;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\IamAdministrationService;
use TowerDNS\Domain\Auth\PermissionRegistry;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\StepUpRequestService;

/**
 * POST /roles — Neue Rolle anlegen.
 */
final readonly class RoleCreateHandler implements RequestHandlerInterface
{
    public function __construct(
        private PermissionRegistry      $permissions,
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
            return new RedirectResponse('/roles?error=' . rawurlencode($this->t('roles.error.invalid-csrf')));
        }

        $name = trim(is_string($body['name'] ?? null) ? $body['name'] : '');

        if ($name === '') {
            return new RedirectResponse('/roles?error=' . rawurlencode($this->t('roles.error.name-required')));
        }

        $rawPerms = $body['permissions'] ?? [];
        $rawPerms = is_array($rawPerms) ? $rawPerms : [$rawPerms];

        $permissions = [];
        foreach ($rawPerms as $pv) {
            try {
                $permissions[] = $this->permissions->assertKnown((string) $pv);
            } catch (\InvalidArgumentException) {
                return new RedirectResponse('/roles?error=' . rawurlencode($this->t('roles.error.invalid-permission')));
            }
        }

        $role = new Role(
            id: Uuid::v4()->toRfc4122(),
            name: $name,
            permissions: $permissions,
        );

        try {
            $proof = $this->stepUpRequests->consume($request, $currentUser->id, StepUpAction::IAM_ROLE_CREATE, 'new');
            $this->iam->saveRole($currentUser, $role, $this->auditContext($request, $currentUser), $proof);
        } catch (StepUpRequiredException $required) {
            return $this->stepUpRequests->challenge($request, $currentUser->id, $required);
        } catch (AuthorizationException) {
            return new RedirectResponse('/roles?error=' . rawurlencode($this->t('http.error.forbidden')));
        } catch (\Throwable) {
            return new RedirectResponse('/roles?error=' . rawurlencode($this->t('roles.error.save-failed')));
        }

        return new RedirectResponse('/roles?success=' . rawurlencode($this->t('roles.success.created', ['name' => $name])));
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

    private function auditContext(ServerRequestInterface $request, User $effectiveUser): AuditContext
    {
        $original = $request->getAttribute('actor_user');
        $switch   = $request->getAttribute('impersonation_session');
        return AuditLogService::fromHttpRequest(
            $request,
            $original instanceof User ? $original->id : $effectiveUser->id,
            $effectiveUser->id,
            impersonationSessionId: $switch instanceof \TowerDNS\Domain\Account\AdminImpersonationSession ? $switch->id : null,
        );
    }
}
