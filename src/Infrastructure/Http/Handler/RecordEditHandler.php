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

/** Loads one record using the managed-zone scope. */
final readonly class RecordEditHandler implements RequestHandlerInterface
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
        $recordId  = (string) $request->getAttribute('record', '');
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard instanceof CsrfGuardInterface ? $guard->generateToken() : '';
        try {
            $record = $this->dns->findRecordForUpdate($user, $accountId, $zoneId, $recordId);
        } catch (AuthorizationException) {
            return $this->render($user, $accountId, $zoneId, null, $csrfToken, $this->translator->translate('http.error.forbidden'), 403);
        } catch (\Throwable) {
            return $this->render($user, $accountId, $zoneId, null, $csrfToken, $this->translator->translate('records.error.read-failed'), 500);
        }
        if (!$record instanceof \TowerDNS\Domain\DNS\Record) {
            return $this->render($user, $accountId, $zoneId, null, $csrfToken, $this->translator->translate('http.error.not-found'), 404);
        }
        return $this->render($user, $accountId, $zoneId, $record, $csrfToken, null, 200);
    }

    private function render(User $user, int $accountId, int $zoneId, mixed $record, string $csrfToken, ?string $error, int $status): HtmlResponse
    {
        return new HtmlResponse($this->renderer->render('app::zones/record_edit', ['user' => $user, 'accountId' => $accountId, 'managedZoneId' => $zoneId, 'record' => $record, 'csrfToken' => $csrfToken, 'error' => $error]), $status);
    }
}
