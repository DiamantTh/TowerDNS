<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\DnsManagementService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\DnsRecordType;
use TowerDNS\Domain\DNS\Rrset;

/** POST /zones/{provider}/{zone}/rrsets — replaces one complete RRset. */
final readonly class RrsetReplaceHandler implements RequestHandlerInterface
{
    public function __construct(
        private DnsManagementService $dns,
        private AuditLogService $audit,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user       = $request->getAttribute(User::class);
        $providerId = (string) $request->getAttribute('provider', '');
        $zoneId     = (string) $request->getAttribute('zone', '');
        $back       = '/zones/' . rawurlencode($providerId) . '/' . rawurlencode($zoneId);
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        if (!$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
            return new HtmlResponse('Ungültige Anfrage.', 400);
        }

        $name  = trim((string) ($body['name'] ?? ''));
        $type  = trim((string) ($body['type'] ?? ''));
        $ttl   = (int) ($body['ttl'] ?? 300);
        $rdata = array_values(array_filter(
            array_map(static fn(string $line): string => trim($line), preg_split('/\R/u', (string) ($body['rdata'] ?? '')) ?: []),
            static fn(string $line): bool => $line !== '',
        ));

        try {
            if ($rdata === []) {
                throw new \InvalidArgumentException('Ein RRset benötigt mindestens einen RDATA-Wert.');
            }
            $rrset   = new Rrset($zoneId, $name, DnsRecordType::parse($type), $ttl, $rdata);
            $written = $this->dns->replaceRrset($user, $providerId, $rrset);
            $this->audit->recordRecordUpdate($request, $user->id, null, $zoneId, $written->ownerName, $written->type->presentation);
        } catch (AuthorizationException) {
            return new RedirectResponse($back . '?error=' . rawurlencode('Keine Berechtigung zum Schreiben von RRsets.'));
        } catch (\Throwable $e) {
            return new RedirectResponse($back . '?error=' . rawurlencode($e->getMessage()));
        }

        return new RedirectResponse($back . '?success=' . rawurlencode('RRset gespeichert und verifiziert.'));
    }
}
