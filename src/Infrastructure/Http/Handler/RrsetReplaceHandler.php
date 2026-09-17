<?php

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
use TowerDNS\Application\Services\ManagedZoneDNSService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\DNSRecordType;
use TowerDNS\Domain\DNS\Rrset;

final readonly class RrsetReplaceHandler implements RequestHandlerInterface
{
    public function __construct(private ManagedZoneDNSService $dns, private AuditLogService $audit, private TranslatorInterface $translator) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(User::class);
        $accountId = (int) $request->getAttribute('account', 0);
        $zoneId = (int) $request->getAttribute('zone', 0);
        $back = '/accounts/' . $accountId . '/zones/' . $zoneId;
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);
        if (!$user instanceof User || !$guard instanceof CsrfGuardInterface || !$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }
        $rdata = array_values(array_filter(array_map(trim(...), preg_split('/\R/u', (string) ($body['rdata'] ?? '')) ?: []), static fn(string $line): bool => $line !== ''));
        if ($rdata === []) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('rrset.error.rdata-required')));
        }
        try {
            $rrset = new Rrset('', trim((string) ($body['name'] ?? '')), DNSRecordType::parse(trim((string) ($body['type'] ?? ''))), (int) ($body['ttl'] ?? 300), $rdata);
            $written = $this->dns->replaceRrset($user, $accountId, $zoneId, $rrset);
            $actor = $request->getAttribute('actor_user');
            $this->audit->recordRecordUpdate($request, $actor instanceof User ? $actor->id : $user->id, $accountId, (string) $zoneId, $written->ownerName, $written->type->presentation);
        } catch (AuthorizationException) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('rrset.error.write-denied')));
        } catch (\Throwable) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('rrset.error.save-failed')));
        }
        return new RedirectResponse($back . '?success=' . rawurlencode($this->translator->translate('rrset.success.saved-verified')));
    }
}
