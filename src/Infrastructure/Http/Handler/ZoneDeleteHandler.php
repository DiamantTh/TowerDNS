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
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\ManagedZoneDNSService;
use TowerDNS\Domain\Auth\User;

/** Deletes exactly one account-owned managed zone. */
final readonly class ZoneDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private ManagedZoneDNSService $dns,
        private ManagedZoneRepositoryInterface $zones,
        private AuditLogService $audit,
        private TranslatorInterface $translator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(User::class);
        if (!$user instanceof User) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }
        $accountId = (int) $request->getAttribute('account', 0);
        $zoneId = (int) $request->getAttribute('zone', 0);
        $back = '/accounts/' . $accountId . '/zones';
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);
        if (!$guard instanceof CsrfGuardInterface || !$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }
        $zone = $this->zones->findByIdForAccount($zoneId, $accountId);
        if (!$zone instanceof \TowerDNS\Domain\Account\ManagedZone) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('http.error.not-found')));
        }
        try {
            $this->dns->delete($user, $accountId, $zoneId);
            $actor = $request->getAttribute('actor_user');
            $this->audit->recordZoneDelete($request, $actor instanceof User ? $actor->id : $user->id, $accountId, (string) $zoneId, $zone->canonicalName);
        } catch (AuthorizationException) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('zones.error.delete-denied')));
        } catch (\Throwable) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('zones.error.delete-failed')));
        }
        return new RedirectResponse($back);
    }
}
