<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use TowerDNS\Application\Contracts\AuthoritativeTlsaCheckerInterface;
use TowerDNS\Application\Contracts\DnssecDiagnosticInterface;
use TowerDNS\Application\DNS\TlsaGenerator;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\ProviderRequestException;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\ManagedZoneDNSService;
use TowerDNS\Application\Validation\DNSNameValidator;
use TowerDNS\Domain\Auth\ApiKeyGrant;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\RateLimit\RateLimiter;
use TowerDNS\Infrastructure\RateLimit\RateLimitExceededException;

/** Read-only certificate-to-public-TLSA diagnostics; never performs TLS connections. */
final readonly class TlsaDiagnosticHandler implements RequestHandlerInterface
{
    public function __construct(
        private ManagedZoneDNSService $dns,
        private TlsaGenerator $generator,
        private AuthoritativeTlsaCheckerInterface $authoritativeChecker,
        private DnssecDiagnosticInterface $dnssecDiagnostic,
        private AuditLogService $audit,
        private CacheInterface $cache,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user    = $request->getAttribute(User::class);
        $grant   = $request->getAttribute(ApiKeyGrant::class);
        $account = (int) $request->getAttribute('account', 0);
        $zone    = (int) $request->getAttribute('zone', 0);
        $api     = str_starts_with($request->getUri()->getPath(), '/api/');
        if (!$user instanceof User || ($api && (!$grant instanceof ApiKeyGrant
            || $grant->accountId !== $account || $grant->zoneId !== $zone
            || !in_array($grant->scope, ['tlsa:read', 'tlsa:append', 'tlsa:write'], true)))) {
            return $this->error('forbidden', 403);
        }

        try {
            $limiterKey = $grant instanceof ApiKeyGrant ? 'key_' . $grant->id : 'user_' . hash('sha256', $user->id);
            new RateLimiter($this->cache, 'tlsa_association_diagnostic_' . $limiterKey, 3, 60, 'TLSA certificate diagnostics')->hit();
            $csrfToken = null;
            if ($api) {
                if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                    return $this->error('json_required', 415);
                }
                $raw = $request->getBody()->read(1048577);
                if (strlen($raw) > 1048576 || !$request->getBody()->eof()) {
                    return $this->error('payload_too_large', 413);
                }
                $body = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
            } else {
                $body  = (array) ($request->getParsedBody() ?? []);
                $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
                if (!$guard instanceof CsrfGuardInterface || !is_string($body['csrf_token'] ?? null)
                                                          || $body['csrf_token'] === '' || !$guard->validateToken($body['csrf_token'], 'tlsa_preview')) {
                    return $this->error('invalid_csrf', 403);
                }
                $csrfToken = $guard->generateToken('tlsa_preview');
            }
            if (!is_array($body) || !is_string($body['owner'] ?? null) || !is_string($body['certificates'] ?? null)
                                 || $body['certificates'] === '' || strlen($body['certificates']) > 1048576) {
                return $this->error('invalid_diagnostic_input', 422);
            }
            $owner = $body['owner'];
            if ($grant instanceof ApiKeyGrant && $grant->owner !== null && $owner !== $grant->owner) {
                return $this->error('forbidden', 403);
            }

            $state       = $this->dns->tlsaState($user, $account, $zone, $owner);
            $zoneName    = $this->dns->tlsaZoneName($user, $account, $zone);
            $hostname    = $this->hostnameFromOwner($state['owner'], $zoneName);
            $publication = $this->authoritativeChecker->check(
                $state['owner'] . '.' . $zoneName,
                $zoneName,
                $state['records'],
                $state['ttl'],
            )->toArray();
            foreach ($publication['servers'] as &$server) {
                if (count($server['records']) > 64) {
                    return $this->error('too_many_public_records', 422);
                }
                $server['certificate_matches'] = $this->generator->matchAssociations(
                    $body['certificates'],
                    $server['records'],
                    $hostname,
                );
            }
            unset($server);
            $dnssec = $this->dnssecDiagnostic->validateTlsa($state['owner'] . '.' . $zoneName, $zoneName);
            $this->audit->record($request, 'tlsa.certificate.diagnostic', 'rrset', $state['owner'], $user->id, $account, (string) $zone, metadata: [
                'api_key_id'        => $grant instanceof ApiKeyGrant ? $grant->id : null,
                'dns_status'        => $publication['status'], 'dnssec_status' => $dnssec->status,
                'certificate_count' => count($this->generator->inspect($body['certificates'])),
            ]);

            return new JsonResponse([
                'owner'                   => $state['owner'],
                'hostname'                => $hostname,
                'checked_at'              => new \DateTimeImmutable()->format(DATE_ATOM),
                'provider_readback'       => $state['records'],
                'provider_etag'           => $state['version'],
                'publication'             => $publication,
                'dnssec_validation'       => $dnssec->toArray(),
                'certificate_source'      => 'user_supplied_public_certificates',
                'server_certificate_seen' => false,
                'dane_authentication'     => 'not_tested',
                'smtp_tls'                => 'not_tested',
                'scope'                   => 'public_dns_and_supplied_certificate_association_only',
                'csrf_token'              => $csrfToken,
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (AuthorizationException) {
            return $this->error('forbidden', 403);
        } catch (\InvalidArgumentException | \JsonException) {
            return $this->error('invalid_diagnostic_input', 422);
        } catch (\DomainException) {
            return $this->error('zone_not_available', 404);
        } catch (RateLimitExceededException $error) {
            return new JsonResponse(['error' => 'rate_limited'], 429, ['Cache-Control' => 'no-store', 'Retry-After' => (string) $error->retryAfter()]);
        } catch (ProviderRequestException) {
            return $this->error('diagnostic_failed', 502);
        }
    }

    private function hostnameFromOwner(string $owner, string $zone): string
    {
        if (preg_match('/^_[1-9][0-9]{0,4}\._(?:tcp|udp|sctp)(?:\.(.+))?$/D', $owner, $matches) !== 1) {
            throw new \InvalidArgumentException('Invalid TLSA owner.');
        }
        $hostname = isset($matches[1]) ? $matches[1] . '.' . $zone : $zone;
        return DNSNameValidator::normalise($hostname);
    }

    private function error(string $code, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $code], $status, ['Cache-Control' => 'no-store']);
    }
}
