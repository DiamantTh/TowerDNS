<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\DNS\TlsaGenerator;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Application\Exception\ProviderRequestException;
use TowerDNS\Application\Exception\RrsetConflictException;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\ManagedZoneDNSService;
use TowerDNS\Domain\Auth\ApiKeyGrant;
use TowerDNS\Domain\Auth\User;

/** @psalm-api Registered in Routes. */
final readonly class TlsaRecordHandler implements RequestHandlerInterface
{
    public function __construct(private ManagedZoneDNSService $dns, private TlsaGenerator $generator, private AuditLogService $audit) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user    = $request->getAttribute(User::class);
        $grant   = $request->getAttribute(ApiKeyGrant::class);
        $account = (int) $request->getAttribute('account', 0);
        $zone    = (int) $request->getAttribute('zone', 0);
        $api     = str_starts_with($request->getUri()->getPath(), '/api/');
        $write   = !in_array($request->getMethod(), ['GET', 'HEAD'], true);
        if (!$user instanceof User || ($api && (!$grant instanceof ApiKeyGrant || $grant->accountId !== $account || $grant->zoneId !== $zone
                                                                               || !in_array($grant->scope, ['tlsa:read', 'tlsa:append', 'tlsa:write'], true) || ($write && $grant->scope === 'tlsa:read')))) {
            return $this->error('forbidden', 403);
        }

        try {
            $query = $request->getQueryParams();
            $owner = $query['owner'] ?? null;
            if ($grant instanceof ApiKeyGrant && $grant->owner !== null) {
                if ($owner !== null && $owner !== $grant->owner) {
                    return $this->error('forbidden', 403);
                }
                $owner = $grant->owner;
            }
            if (!$write && $owner === null) {
                $rrsets  = array_values(array_filter($this->dns->rrsetListPage($user, $account, $zone)->rrsets, static fn(\TowerDNS\Domain\DNS\Rrset $set): bool => $set->type->presentation === 'TLSA'));
                $records = array_map(static fn(\TowerDNS\Domain\DNS\Rrset $set): array => [
                    'owner' => $set->ownerName, 'ttl' => $set->ttl, 'records' => $set->rdata,
                ], $rrsets);
                return new JsonResponse(['rrsets' => $records, 'verification' => 'provider_only'], 200, ['Cache-Control' => 'no-store']);
            }
            if (!is_string($owner) || $owner === '') {
                return $this->error('invalid_owner', 422);
            }
            if (!$write) {
                $state = $this->dns->tlsaState($user, $account, $zone, $owner);
                $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
                $token = !$api && $guard instanceof CsrfGuardInterface ? $guard->generateToken('tlsa_change') : null;
                return $this->snapshot($state, $token);
            }

            if ($api) {
                if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                    return $this->error('json_required', 415);
                }
                $raw = $request->getBody()->read(2097153);
                if (strlen($raw) > 2097152 || !$request->getBody()->eof()) {
                    return $this->error('payload_too_large', 413);
                }
                $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                $tag  = $request->getHeaderLine('If-Match');
            } else {
                $form  = (array) $request->getParsedBody();
                $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
                if (!$guard instanceof CsrfGuardInterface || !is_string($form['csrf_token'] ?? null) || $form['csrf_token'] === '' || !$guard->validateToken($form['csrf_token'], 'tlsa_change')) {
                    return $this->error('invalid_csrf', 403);
                }
                $body = json_decode(is_string($form['change'] ?? null) ? $form['change'] : '', true, 16, JSON_THROW_ON_ERROR);
                $tag  = $request->getHeaderLine('If-Match');
            }

            if (!is_array($body)) {
                return $this->error('invalid_change', 422);
            }
            if (preg_match('/^"([0-9a-f]{64})"$/D', $tag, $version) !== 1) {
                return $this->error('if_match_required', 428);
            }
            $add    = $this->values($body['add'] ?? []);
            $remove = $this->values($body['remove'] ?? []);
            if ($remove !== [] && $grant instanceof ApiKeyGrant && $grant->scope !== 'tlsa:write') {
                return $this->error('forbidden', 403);
            }
            if (isset($body['certificates'])) {
                if (!is_string($body['certificates']) || !is_int($body['usage'] ?? null) || !is_int($body['selector'] ?? null) || !is_int($body['matching'] ?? null)) {
                    return $this->error('invalid_certificate_parameters', 422);
                }
                $add = [...$add, ...$this->generator->generate($body['certificates'], $body['usage'], $body['selector'], $body['matching'])];
            }
            $ttl = $body['ttl'] ?? 300;
            if (!is_int($ttl) || ($add === [] && $remove === [])) {
                return $this->error('invalid_change', 422);
            }
            $state = $this->dns->changeTlsa($user, $account, $zone, $owner, $add, $remove, $version[1], $ttl);
            $this->audit->record($request, 'tlsa.change', 'rrset', $state['owner'], $user->id, $account, (string) $zone, metadata: [
                'api_key_id' => $grant instanceof ApiKeyGrant ? $grant->id : null,
                'add_count'  => count($add), 'remove_count' => count($remove),
            ]);
            return $this->snapshot($state);
        } catch (AuthorizationException) {
            return $this->error('forbidden', 403);
        } catch (RrsetConflictException) {
            return $this->error('rrset_conflict', 412);
        } catch (CapabilityException) {
            return $this->error('conditional_write_unavailable', 409);
        } catch (\InvalidArgumentException | \JsonException) {
            return $this->error('invalid_change', 422);
        } catch (\DomainException) {
            return $this->error('zone_not_available', 404);
        } catch (ProviderRequestException) {
            return $this->error('provider_error', 502);
        }
    }

    /** @return list<string> */
    private function values(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > 64) {
            throw new \InvalidArgumentException('Expected a bounded list of TLSA values.');
        }
        $records = [];
        foreach ($values as $value) {
            if (!is_string($value) || strlen($value) > 131080) {
                throw new \InvalidArgumentException('Invalid TLSA value.');
            }
            $records[] = $value;
        }
        return $records;
    }

    /** @param array{owner: string, ttl: ?int, records: list<string>, version: string, conditional: bool} $state */
    private function snapshot(array $state, ?string $token = null): JsonResponse
    {
        return new JsonResponse($state + ['verification' => 'provider_only', 'csrf_token' => $token], 200, [
            'ETag' => '"' . $state['version'] . '"', 'Cache-Control' => 'no-store',
        ]);
    }

    private function error(string $code, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $code], $status, ['Cache-Control' => 'no-store']);
    }
}
