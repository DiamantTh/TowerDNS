<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\MonitoringService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

/**
 * Administrative, read-only monitoring overview and explicit per-zone configuration.
 * @psalm-api Registered in Routes.
 */
final readonly class MonitoringHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private AuthorizationService $authorization,
        private MonitoringService $monitoring,
        private AuditLogService $audit,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(User::class);
        if (!$user instanceof User || !$this->authorization->isGranted($user, Permission::SYSTEM_SETTINGS_MANAGE)) {
            return new HtmlResponse('', 403);
        }
        if (strtoupper($request->getMethod()) === 'POST') {
            return $this->save($request, $user);
        }

        /** @var CsrfGuardInterface $guard */
        $guard  = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $query  = $request->getQueryParams();
        $zoneId = is_string($query['zone'] ?? null) && preg_match('/\A[1-9][0-9]{0,8}\z/D', $query['zone']) === 1 ? (int) $query['zone'] : null;
        return new HtmlResponse($this->renderer->render('app::admin/monitoring', [
            'user'           => $user,
            'zones'          => $this->monitoring->overview(),
            'history'        => $zoneId === null ? [] : $this->monitoring->history($zoneId),
            'selectedZoneId' => $zoneId,
            'csrfToken'      => $guard->generateToken(),
            'success'        => ($query['success'] ?? null) === '1' ? 'monitoring.saved' : null,
            'error'          => ($query['error'] ?? null)   === 'monitoring.invalid' ? 'monitoring.invalid' : null,
        ]));
    }

    private function save(ServerRequestInterface $request, User $user): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new RedirectResponse('/admin/monitoring?error=monitoring.invalid');
        }
        $zoneId   = is_string($body['managed_zone_id'] ?? null) ? $body['managed_zone_id'] : '';
        $interval = is_string($body['interval_minutes'] ?? null) ? $body['interval_minutes'] : '';
        if (preg_match('/\A[1-9][0-9]{0,8}\z/D', $zoneId) !== 1 || preg_match('/\A[0-9]{1,4}\z/D', $interval) !== 1) {
            return new RedirectResponse('/admin/monitoring?error=monitoring.invalid');
        }
        $enabled  = ($body['enabled'] ?? null)           === '1';
        $smtpDane = ($body['smtp_dane_enabled'] ?? null) === '1';
        try {
            $this->monitoring->configure((int) $zoneId, $enabled, $smtpDane, (int) $interval, $user->id);
        } catch (\InvalidArgumentException) {
            return new RedirectResponse('/admin/monitoring?error=monitoring.invalid');
        }
        $zone = array_find($this->monitoring->overview(), fn($candidate): bool => (int) ($candidate['managed_zone_id'] ?? 0) === (int) $zoneId);
        $this->audit->record(
            $request,
            'monitoring.configure',
            'managed_zone',
            $zoneId,
            $user->id,
            is_array($zone) ? (int) $zone['account_id'] : null,
            is_array($zone) ? (string) $zone['canonical_name'] : null,
            is_array($zone) ? (int) $zone['provider_account_id'] : null,
            after: ['enabled' => $enabled, 'smtp_dane_enabled' => $smtpDane, 'interval_minutes' => (int) $interval],
        );
        return new RedirectResponse('/admin/monitoring?success=1');
    }
}
