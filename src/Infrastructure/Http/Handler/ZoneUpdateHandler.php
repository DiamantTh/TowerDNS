<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Services\DnsManagementService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;

/**
 * POST /zones/{provider}/{zone}/records/{record}/update — updates an existing DNS record.
 */
final class ZoneUpdateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly DnsManagementService $dns,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user       = $request->getAttribute(User::class);
        $providerId = (string) $request->getAttribute('provider', '');
        $zoneId     = (string) $request->getAttribute('zone', '');
        $recordId   = (string) $request->getAttribute('record', '');

        $back = '/zones/' . rawurlencode($providerId) . '/' . rawurlencode($zoneId);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new RedirectResponse($back . '?error=' . rawurlencode('Ungültige Anfrage.'));
        }

        $name    = trim((string) ($body['name'] ?? ''));
        $typeRaw = strtoupper(trim((string) ($body['type'] ?? '')));
        $ttl     = max(1, (int) ($body['ttl'] ?? 300));
        $content = trim((string) ($body['content'] ?? ''));
        $comment = trim((string) ($body['comment'] ?? ''));

        if ($name === '' || $typeRaw === '' || $content === '') {
            $editUrl = '/zones/' . rawurlencode($providerId) . '/' . rawurlencode($zoneId)
                . '/records/' . rawurlencode($recordId) . '/edit';
            return new RedirectResponse($editUrl . '?error=' . rawurlencode('Name, Typ und Inhalt sind erforderlich.'));
        }

        $type = RecordType::tryFrom($typeRaw);
        if ($type === null) {
            return new RedirectResponse($back . '?error=' . rawurlencode('Unbekannter Record-Typ: ' . $typeRaw));
        }

        $record = new Record(
            id:      $recordId,
            zoneId:  $zoneId,
            name:    $name,
            type:    $type,
            ttl:     $ttl,
            content: $content,
            comment: $comment !== '' ? $comment : null,
        );

        try {
            $this->dns->updateRecord($user, $providerId, $record);
        } catch (AuthorizationException) {
            return new RedirectResponse($back . '?error=' . rawurlencode('Keine Berechtigung zum Bearbeiten von Einträgen.'));
        } catch (\Throwable $e) {
            return new RedirectResponse($back . '?error=' . rawurlencode($e->getMessage()));
        }

        return new RedirectResponse($back . '?success=' . rawurlencode('Eintrag aktualisiert.'));
    }
}
