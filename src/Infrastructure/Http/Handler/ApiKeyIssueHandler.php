<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\ProviderRequestException;
use TowerDNS\Application\Repository\ApiKeyRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\ManagedZoneDNSService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Application\Services\TlsaApiKeyScopePolicy;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Middleware\ForceHttpsMiddleware;

/** @psalm-api Registered in Routes. */
final readonly class ApiKeyIssueHandler implements RequestHandlerInterface
{
    public function __construct(
        private ApiKeyRepositoryInterface $keys,
        private ManagedZoneDNSService $dns,
        private PermissionService $permissions,
        private AuditLogService $audit,
        private ForceHttpsMiddleware $https,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $headers = ['Cache-Control' => 'no-store'];
        $user    = $request->getAttribute(User::class);
        $guard   = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body    = (array) $request->getParsedBody();
        if (!$this->https->isSecure($request) || !$user instanceof User || !$user->active
                                              || !$guard instanceof CsrfGuardInterface || !is_string($body['csrf_token'] ?? null) || $body['csrf_token'] === '' || !$guard->validateToken($body['csrf_token'])) {
            return new JsonResponse(['error' => 'forbidden'], 403, $headers);
        }
        $account = filter_var($body['account'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $zone    = filter_var($body['zone'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $name    = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $scope   = $body['scope'] ?? null;
        $owner   = $body['owner'] ?? '';
        if (!is_int($account) || !is_int($zone) || $name === '' || strlen($name) > 100
                              || !in_array($scope, ['tlsa:read', 'tlsa:append', 'tlsa:write', 'monitoring:read'], true) || !is_string($owner)
                              || (in_array($scope, ['tlsa:append', 'tlsa:write'], true) && trim($owner) === '')
                              || ($scope === 'monitoring:read' && trim($owner) !== '')) {
            return new JsonResponse(['error' => 'invalid_scope'], 422, $headers);
        }
        try {
            if (!$this->permissions->authorizeManagedZone($user, Permission::RECORD_READ, $account, $zone)
                || (in_array($scope, ['tlsa:append', 'tlsa:write'], true) && !$this->permissions->authorizeManagedZone($user, Permission::RECORD_UPDATE, $account, $zone))
                || ($scope === 'tlsa:write' && !$this->permissions->authorizeManagedZone($user, Permission::RECORD_DELETE, $account, $zone))) {
                return new JsonResponse(['error' => 'forbidden'], 403, $headers);
            }
            if (trim($owner) !== '') {
                $state = $this->dns->tlsaState($user, $account, $zone, $owner);
                if ($scope !== 'tlsa:read' && $state['records'] === []
                                           && !$this->permissions->authorizeManagedZone($user, Permission::RECORD_CREATE, $account, $zone)) {
                    return new JsonResponse(['error' => 'forbidden'], 403, $headers);
                }
                if (!new TlsaApiKeyScopePolicy()->supports(
                    $scope,
                    $state['conditional'] || $state['capabilities']['conditional_replace'],
                    $state['capabilities']['append_record'],
                    $state['capabilities']['remove_record'],
                )) {
                    return new JsonResponse(['error' => 'provider_operation_unavailable'], 409, $headers);
                }
                $owner = $state['owner'];
            } else {
                $owner = null;
            }
        } catch (AuthorizationException | \DomainException) {
            return new JsonResponse(['error' => 'forbidden'], 403, $headers);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'invalid_scope'], 422, $headers);
        } catch (ProviderRequestException) {
            return new JsonResponse(['error' => 'provider_error'], 502, $headers);
        }

        $token  = 'tdns_' . bin2hex(random_bytes(32));
        $expiry = new \DateTimeImmutable('+90 days', new \DateTimeZone('UTC'));
        $this->keys->create($user->id, $name, hash('sha256', $token), $scope, $account, $zone, $owner, $expiry);
        $this->audit->record($request, 'api_key.create', 'api_key', actorUserId: $user->id, accountId: $account, zoneId: (string) $zone, metadata: ['scope' => $scope, 'owner' => $owner]);
        return new JsonResponse(['token' => $token, 'expires_at' => $expiry->format(DATE_ATOM)], 201, $headers);
    }
}
