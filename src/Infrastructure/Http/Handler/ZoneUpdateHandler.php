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
use TowerDNS\Application\Validation\RecordInputFilter;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;

/**
 * POST /zones/{provider}/{zone}/records/{record}/update — updates an existing DNS record.
 */
final readonly class ZoneUpdateHandler implements RequestHandlerInterface
{
    public function __construct(
        private DnsManagementService $dns,
    ) {}

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

        $recordFilter = new RecordInputFilter();
        $recordFilter->setData([
            'name'    => $name,
            'type'    => $typeRaw,
            'ttl'     => $ttl,
            'content' => $content,
        ]);
        if (!$recordFilter->isValid()) {
            $messages = array_values($recordFilter->getMessages());
            $first    = reset($messages);
            $inner    = is_array($first) ? reset($first) : null;
            $msg      = is_string($inner) ? $inner : 'Ungültige Eingabe.';
            $editUrl  = '/zones/' . rawurlencode($providerId) . '/' . rawurlencode($zoneId)
                . '/records/' . rawurlencode($recordId) . '/edit';
            return new RedirectResponse($editUrl . '?error=' . rawurlencode($msg));
        }
        $fv      = $recordFilter->getValues();
        $name    = trim((string) ($fv['name'] ?? ''));
        $typeRaw = strtoupper(trim((string) ($fv['type'] ?? '')));
        $ttl     = max(1, (int) ($fv['ttl'] ?? 300));
        $content = trim((string) ($fv['content'] ?? ''));

        $type = RecordType::tryFrom($typeRaw);
        if ($type === null) {
            return new RedirectResponse($back . '?error=' . rawurlencode('Unbekannter Record-Typ: ' . $typeRaw));
        }

        $record = new Record(
            id: $recordId,
            zoneId: $zoneId,
            name: $name,
            type: $type,
            ttl: $ttl,
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
