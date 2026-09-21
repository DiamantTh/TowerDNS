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
            $page = $this->dns->rrsetListPage($user, $accountId, $zoneId);
        } catch (AuthorizationException) {
            return $this->render($user, $accountId, $zoneId, [], $csrfToken, $this->translator->translate('http.error.forbidden'), 403);
        } catch (\Throwable) {
            return $this->render($user, $accountId, $zoneId, [], $csrfToken, $this->translator->translate('records.error.read-failed'), 500);
        }
        $query = $request->getQueryParams();
        return $this->render($user, $accountId, $zoneId, $this->displayRrsets($page->rrsets), $csrfToken, is_string($query['error'] ?? null) ? $query['error'] : null, 200, is_string($query['success'] ?? null) ? $query['success'] : null, $page->managedZoneName, $page->canReplaceRrsets, $page->canDeleteRrsets);
    }

    /** @param list<\TowerDNS\Domain\DNS\Rrset> $rrsets
     *  @return list<array{ownerName: string, type: array{presentation: string, code: int, isKnown: bool}, ttl: int, rdata: list<string>}>
     */
    private function displayRrsets(array $rrsets): array
    {
        return array_map(static fn(\TowerDNS\Domain\DNS\Rrset $rrset): array => [
            'ownerName' => $rrset->ownerName,
            'type'      => [
                'presentation' => $rrset->type->presentation,
                'code'         => $rrset->type->code,
                'isKnown'      => $rrset->type->isKnown,
            ],
            'ttl'   => $rrset->ttl,
            'rdata' => $rrset->rdata,
        ], $rrsets);
    }

    /** @param list<array{ownerName: string, type: array{presentation: string, code: int, isKnown: bool}, ttl: int, rdata: list<string>}> $rrsets */
    private function render(User $user, int $accountId, int $zoneId, array $rrsets, string $csrfToken, ?string $error, int $status, ?string $success = null, ?string $managedZoneName = null, bool $canReplaceRrsets = false, bool $canDeleteRrsets = false): HtmlResponse
    {
        return new HtmlResponse($this->renderer->render('app::zones/records', ['user' => $user, 'accountId' => $accountId, 'managedZoneId' => $zoneId, 'managedZoneName' => $managedZoneName, 'rrsets' => $rrsets, 'csrfToken' => $csrfToken, 'error' => $error, 'success' => $success, 'canReplaceRrsets' => $canReplaceRrsets, 'canDeleteRrsets' => $canDeleteRrsets]), $status);
    }
}
