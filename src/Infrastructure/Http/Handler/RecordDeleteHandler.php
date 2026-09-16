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

/**
 * POST /zones/{provider}/{zone}/records/{record}/delete — removes a DNS record.
 *
 * Uses POST so plain HTML forms work without JavaScript.
 */
final readonly class RecordDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private DNSManagementService $dns,
        private AuditLogService      $audit,
        private TranslatorInterface  $translator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }

        /** @var User $user */
        $user       = $request->getAttribute(User::class);
        $providerId = (string) $request->getAttribute('provider', '');
        $zoneId     = (string) $request->getAttribute('zone', '');
        $recordId   = (string) $request->getAttribute('record', '');

        $back = '/zones/' . rawurlencode($providerId) . '/' . rawurlencode($zoneId);

        try {
            $this->dns->deleteRecord($user, $providerId, $zoneId, $recordId);
            $this->audit->recordRecordDelete($request, $user->id, null, $zoneId, $recordId, '');
        } catch (AuthorizationException) {
            return new RedirectResponse($back . '?error=' . rawurlencode(
                $this->translator->translate('records.error.delete-denied'),
            ));
        } catch (\Throwable) {
            return new RedirectResponse($back . '?error=' . rawurlencode(
                $this->translator->translate('records.error.delete-failed'),
            ));
        }

        return new RedirectResponse($back);
    }
}
