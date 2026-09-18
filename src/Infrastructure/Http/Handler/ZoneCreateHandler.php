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

/** Creates an account-owned managed zone. */
final readonly class ZoneCreateHandler implements RequestHandlerInterface
{
    public function __construct(
        private ManagedZoneDNSService $dns,
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
        $back      = '/accounts/' . $accountId . '/zones';
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body      = (array) ($request->getParsedBody() ?? []);
        if (!$guard instanceof CsrfGuardInterface || !$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }

        $name              = trim((string) ($body['zone_name'] ?? ''));
        $providerAccountId = (int) ($body['provider_account_id'] ?? 0);
        if ($name === '' || $providerAccountId <= 0) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('zones.error.name-required')));
        }
        try {
            $zone  = $this->dns->create($user, $accountId, $providerAccountId, $name);
            $actor = $request->getAttribute('actor_user');
            $this->audit->recordZoneCreate($request, $actor instanceof User ? $actor->id : $user->id, $accountId, (string) $zone->id, $zone->canonicalName);
        } catch (AuthorizationException) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('zones.error.create-denied')));
        } catch (\Throwable) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('zones.error.create-failed')));
        }
        return new RedirectResponse($back);
    }
}
