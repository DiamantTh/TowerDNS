<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\DNS\TlsaGenerator;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

/**
 * Session-authenticated, CSRF-protected TLSA preview. Does not write DNS.
 * Browser requests never invoke external programs or probe network targets.
 */
final readonly class TlsaGenerateHandler implements RequestHandlerInterface
{
    public function __construct(
        private TlsaGenerator $generator,
        private PermissionService $permissions,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(User::class);
        $accountId = (int) $request->getAttribute('account', 0);
        $zoneId = (int) $request->getAttribute('zone', 0);
        if (!$user instanceof User || !$this->permissions->authorizeManagedZone($user, Permission::RECORD_UPDATE, $accountId, $zoneId)) {
            return new JsonResponse(['error' => 'Not authorized for DNS record updates.'], 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        if (!$guard instanceof CsrfGuardInterface || !$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 400);
        }
        if (!is_string($body['pem'] ?? null)) {
            return new JsonResponse(['error' => 'Expected PEM certificate data.'], 422);
        }
        foreach (['usage', 'selector', 'matching'] as $field) {
            if (!is_string($body[$field] ?? null) || preg_match('/^[0-9]$/D', $body[$field]) !== 1) {
                return new JsonResponse(['error' => 'Invalid TLSA option.'], 422);
            }
        }

        try {
            $rdata = $this->generator->generate($body['pem'], (int) $body['usage'], (int) $body['selector'], (int) $body['matching']);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 422);
        }
        return (new JsonResponse(['rdata' => $rdata]))->withHeader('Cache-Control', 'no-store');
    }
}
