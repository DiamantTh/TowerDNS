<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\MonitoringService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Domain\Auth\ApiKeyGrant;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Persistence\MonitoringAlerts;
use TowerDNS\Infrastructure\RateLimit\RateLimiter;
use TowerDNS\Infrastructure\RateLimit\RateLimitExceededException;

/** @psalm-api Registered in Routes. */
final readonly class ZoneMonitoringHandler implements RequestHandlerInterface
{
    public function __construct(private PermissionService $permissions, private MonitoringAlerts $alerts, private TemplateRendererInterface $renderer, private MonitoringService $monitoring, private AuditLogService $audit, private CacheInterface $cache) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $api     = str_starts_with($request->getUri()->getPath(), '/api/');
        $user    = $request->getAttribute(User::class);
        $grant   = $request->getAttribute(ApiKeyGrant::class);
        $account = (int) $request->getAttribute('account');
        $zone    = (int) $request->getAttribute('zone');
        if (!$user instanceof User
            || ($api && (!$grant instanceof ApiKeyGrant || $grant->scope     !== 'monitoring:read' || $grant->owner !== null
                                                        || $grant->accountId !== $account || $grant->zoneId !== $zone))
            || !$this->permissions->authorizeManagedZone($user, Permission::RECORD_READ, $account, $zone)) {
            return $this->error($api, 'forbidden', 403);
        }
        if ($api) {
            try {
                new RateLimiter($this->cache, 'monitoring_api_' . $grant->id, 60, 60, 'Monitoring API')->hit();
            } catch (RateLimitExceededException) {
                return new JsonResponse(['error' => 'rate_limited'], 429, ['Retry-After' => '60', 'Cache-Control' => 'no-store']);
            }
        }
        $canConfigure = !$api && $this->permissions->authorizeManagedZone($user, Permission::ZONE_UPDATE, $account, $zone);
        $guard        = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $action       = "/accounts/{$account}/zones/{$zone}/monitoring";
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            if (!$canConfigure || !$guard instanceof CsrfGuardInterface || !is_array($body) || !is_string($body['csrf_token'] ?? null)
                               || !$guard->validateToken($body['csrf_token'])) {
                return $this->error($api, 'forbidden', 403);
            }
            $interval = filter_var($body['interval_minutes'] ?? null, FILTER_VALIDATE_INT);
            if (!is_int($interval) || $interval < 5 || $interval > 1440) {
                return new RedirectResponse($action . '?invalid=1', 303);
            }
            $enabled = ($body['enabled'] ?? null)           === '1';
            $smtp    = ($body['smtp_dane_enabled'] ?? null) === '1';
            $this->monitoring->configure($zone, $enabled, $smtp, $interval, $user->id);
            $this->audit->record(
                $request,
                'monitoring.configure',
                'managed_zone',
                (string) $zone,
                $user->id,
                $account,
                after: ['enabled' => $enabled, 'smtp_dane_enabled' => $smtp, 'interval_minutes' => $interval]
            );
            return new RedirectResponse($action, 303);
        }
        $query  = $request->getQueryParams();
        $before = filter_var($query['before'] ?? PHP_INT_MAX, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $days   = filter_var($query['days'] ?? 30, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 180]]);
        if (!is_int($before) || !is_int($days)) {
            return $this->error($api, 'invalid_filter', 422);
        }
        $state = $this->alerts->status($account, $zone);
        if ($state === false) {
            return $this->error($api, 'zone_not_found', 404);
        }
        $events                     = $this->alerts->page($zone, gmdate('Y-m-d H:i:s', time() - $days * 86400), $before, 51);
        $events                     = array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'kind' => $row['check_type'], 'previous_status' => $row['previous_status'], 'status' => $row['status'], 'severity' => $row['severity'], 'occurred_at' => $row['occurred_at']], array_slice($events, 0, 51));
        $next                       = count($events) === 51 ? $events[49]['id'] : null;
        $events                     = array_slice($events, 0, 50);
        $age                        = is_string($state['checked_at']) ? max(0, time() - (int) strtotime($state['checked_at'] . ' UTC')) : null;
        $state['enabled']           = (bool) $state['enabled'];
        $state['smtp_dane_enabled'] = (bool) $state['smtp_dane_enabled'];
        $state['interval_minutes']  = (int) ($state['interval_minutes'] ?? 60);
        $state['age_seconds']       = $age;
        $state['stale']             = $age !== null && $age > $state['interval_minutes'] * 60;
        $state['dane_handshake']    = 'not_tested';
        if ($api) {
            return new JsonResponse(['status' => $state, 'events' => $events, 'next_before' => $next, 'deliveries' => $this->alerts->deliveries($zone)], 200, ['Cache-Control' => 'no-store']);
        }
        return new HtmlResponse($this->renderer->render('app::admin/monitoring', [
            'user'             => $user, 'monitoringReadOnly' => true, 'monitoringCanConfigure' => $canConfigure,
            'monitoringAction' => $action, 'csrfToken' => $canConfigure && $guard instanceof CsrfGuardInterface ? $guard->generateToken() : '',
            'zones'            => [$state], 'history' => $events,
            'error'            => ($query['invalid'] ?? null) === '1' ? 'monitoring.invalid' : null,
            'selectedZoneId'   => $zone, 'monitoringBackHref' => "/accounts/{$account}/zones/{$zone}",
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    private function error(bool $api, string $code, int $status): ResponseInterface
    {
        return $api ? new JsonResponse(['error' => $code], $status) : new HtmlResponse('', $status);
    }
}
