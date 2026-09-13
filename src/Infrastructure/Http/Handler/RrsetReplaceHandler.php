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
use TowerDNS\Domain\DNS\DNSRecordType;
use TowerDNS\Domain\DNS\Rrset;

/** POST /zones/{provider}/{zone}/rrsets — replaces one complete RRset. */
final readonly class RrsetReplaceHandler implements RequestHandlerInterface
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
        $back       = '/zones/' . rawurlencode($providerId) . '/' . rawurlencode($zoneId);
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        if (!$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }

        $name  = trim((string) ($body['name'] ?? ''));
        $type  = trim((string) ($body['type'] ?? ''));
        $ttl   = (int) ($body['ttl'] ?? 300);
        $rdata = array_values(array_filter(
            array_map(trim(...), preg_split('/\R/u', (string) ($body['rdata'] ?? '')) ?: []),
            static fn(string $line): bool => $line !== '',
        ));

        try {
            if ($rdata === []) {
                return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('rrset.error.rdata-required')));
            }
            $rrset   = new Rrset($zoneId, $name, DNSRecordType::parse($type), $ttl, $rdata);
            $written = $this->dns->replaceRrset($user, $providerId, $rrset);
            $this->audit->recordRecordUpdate($request, $user->id, null, $zoneId, $written->ownerName, $written->type->presentation);
        } catch (AuthorizationException) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('rrset.error.write-denied')));
        } catch (\Throwable) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('rrset.error.save-failed')));
        }

        return new RedirectResponse($back . '?success=' . rawurlencode($this->translator->translate('rrset.success.saved-verified')));
    }
}
