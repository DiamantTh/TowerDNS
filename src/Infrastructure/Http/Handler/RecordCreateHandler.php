<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Services\DnsManagementService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;

/**
 * POST /zones/{provider}/{zone}/records — creates a new DNS record.
 */
final class RecordCreateHandler implements RequestHandlerInterface
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

        $back = '/zones/' . rawurlencode($providerId) . '/' . rawurlencode($zoneId);

        /** @var array<string, string> $body */
        $body    = (array) ($request->getParsedBody() ?? []);
        $name    = trim((string) ($body['name'] ?? ''));
        $typeRaw = strtoupper(trim((string) ($body['type'] ?? '')));
        $ttl     = max(1, (int) ($body['ttl'] ?? 300));
        $content = trim((string) ($body['content'] ?? ''));
        $comment = trim((string) ($body['comment'] ?? ''));

        if ($name === '' || $typeRaw === '' || $content === '') {
            return new RedirectResponse($back . '?error=' . rawurlencode('Name, Typ und Inhalt sind erforderlich.'));
        }

        $type = RecordType::tryFrom($typeRaw);
        if ($type === null) {
            return new RedirectResponse($back . '?error=' . rawurlencode('Unbekannter Record-Typ: ' . $typeRaw));
        }

        $record = new Record(
            id:      '',
            zoneId:  $zoneId,
            name:    $name,
            type:    $type,
            ttl:     $ttl,
            content: $content,
            comment: $comment !== '' ? $comment : null,
        );

        try {
            $this->dns->createRecord($user, $providerId, $record);
        } catch (AuthorizationException) {
            return new RedirectResponse($back . '?error=' . rawurlencode('Keine Berechtigung zum Anlegen von Einträgen.'));
        } catch (\Throwable $e) {
            return new RedirectResponse($back . '?error=' . rawurlencode($e->getMessage()));
        }

        return new RedirectResponse($back);
    }
}
