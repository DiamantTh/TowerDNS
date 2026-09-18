<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
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

/** Lists RRsets for one local managed zone. */
final readonly class RecordListHandler implements RequestHandlerInterface
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
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard instanceof CsrfGuardInterface ? $guard->generateToken() : '';
        try {
            $rrsets = $this->dns->listRrsets($user, $accountId, $zoneId);
        } catch (AuthorizationException) {
            return $this->render($user, $accountId, $zoneId, [], $csrfToken, $this->translator->translate('http.error.forbidden'), 403);
        } catch (\Throwable) {
            return $this->render($user, $accountId, $zoneId, [], $csrfToken, $this->translator->translate('records.error.read-failed'), 500);
        }
        $query = $request->getQueryParams();
        return $this->render($user, $accountId, $zoneId, $rrsets, $csrfToken, is_string($query['error'] ?? null) ? $query['error'] : null, 200, is_string($query['success'] ?? null) ? $query['success'] : null);
    }

    /** @param list<\TowerDNS\Domain\DNS\Rrset> $rrsets */
    private function render(User $user, int $accountId, int $zoneId, array $rrsets, string $csrfToken, ?string $error, int $status, ?string $success = null): HtmlResponse
    {
        return new HtmlResponse($this->renderer->render('app::zones/records', ['user' => $user, 'accountId' => $accountId, 'managedZoneId' => $zoneId, 'rrsets' => $rrsets, 'csrfToken' => $csrfToken, 'error' => $error, 'success' => $success]), $status);
    }
}
