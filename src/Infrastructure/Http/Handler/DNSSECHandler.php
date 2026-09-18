<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\I18n\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Services\ManagedZoneDNSService;
use TowerDNS\Domain\Auth\User;

final readonly class DNSSECHandler implements RequestHandlerInterface
{
    public function __construct(private TemplateRendererInterface $renderer, private ManagedZoneDNSService $dns, private TranslatorInterface $translator) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(User::class);
        if (!$user instanceof User) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }
        $accountId = (int) $request->getAttribute('account', 0);
        $zoneId    = (int) $request->getAttribute('zone', 0);
        $back      = '/accounts/' . $accountId . '/zones/' . $zoneId . '/dnssec';
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard instanceof CsrfGuardInterface ? $guard->generateToken() : '';
        if ($request->getMethod() === 'POST') {
            $body = (array) ($request->getParsedBody() ?? []);
            if (!$guard instanceof CsrfGuardInterface || !$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
                return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('http.error.invalid-request')));
            }
            $action = trim((string) ($body['action'] ?? ''));
            if ($action === '') {
                return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('dnssec.error.action-required')));
            }
            unset($body['csrf_token'], $body['action']);
            try {
                $this->dns->executeDnssecAction($user, $accountId, $zoneId, $action, $body);
            } catch (\Throwable) {
                return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('dnssec.error.action-failed')));
            }
            return new RedirectResponse($back . '?success=' . rawurlencode($this->translator->translate(['enable' => 'dnssec.success.enabled', 'disable' => 'dnssec.success.disabled'][$action] ?? 'dnssec.success.action-executed')));
        }
        try {
            $profile = $this->dns->dnssecProfile($user, $accountId, $zoneId);
        } catch (AuthorizationException) {
            return $this->render($user, $accountId, $zoneId, null, $csrfToken, $this->translator->translate('dnssec.error.read-denied'), 403);
        } catch (\Throwable) {
            return $this->render($user, $accountId, $zoneId, null, $csrfToken, $this->translator->translate('dnssec.error.read-failed'), 500);
        }
        $query = $request->getQueryParams();
        return $this->render($user, $accountId, $zoneId, $profile, $csrfToken, is_string($query['error'] ?? null) ? $query['error'] : null, 200, is_string($query['success'] ?? null) ? $query['success'] : null);
    }
    private function render(User $user, int $accountId, int $zoneId, mixed $profile, string $csrfToken, ?string $error, int $status, ?string $success = null): HtmlResponse
    {
        return new HtmlResponse($this->renderer->render('app::zones/dnssec', ['user' => $user, 'accountId' => $accountId, 'managedZoneId' => $zoneId, 'profile' => $profile, 'csrfToken' => $csrfToken, 'error' => $error, 'success' => $success]), $status);
    }
}
