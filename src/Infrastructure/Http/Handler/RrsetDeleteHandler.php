<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\I18n\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\DNSManagementService;
use TowerDNS\Domain\Auth\User;

/** POST /zones/{provider}/{zone}/rrsets/{owner}/{type}/delete — deletes a complete RRset. */
final readonly class RrsetDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private DNSManagementService $dns,
        private AuditLogService $audit,
        private TranslatorInterface $translator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user       = $request->getAttribute(User::class);
        $providerId = (string) $request->getAttribute('provider', '');
        $zoneId     = (string) $request->getAttribute('zone', '');
        $ownerName  = (string) $request->getAttribute('owner', '');
        $type       = (string) $request->getAttribute('type', '');
        $back       = '/zones/' . rawurlencode($providerId) . '/' . rawurlencode($zoneId);
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        if (!$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }

        try {
            $this->dns->deleteRrset($user, $providerId, $zoneId, $ownerName, $type);
            $this->audit->recordRecordDelete($request, $user->id, null, $zoneId, $ownerName, $type);
        } catch (AuthorizationException) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('rrset.error.delete-denied')));
        } catch (\Throwable) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('rrset.error.delete-failed')));
        }

        return new RedirectResponse($back . '?success=' . rawurlencode($this->translator->translate('rrset.success.deleted-verified')));
    }
}
