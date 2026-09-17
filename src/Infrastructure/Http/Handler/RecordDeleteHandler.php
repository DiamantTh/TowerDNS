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

final readonly class RecordDeleteHandler implements RequestHandlerInterface
{
    public function __construct(private ManagedZoneDNSService $dns, private AuditLogService $audit, private TranslatorInterface $translator) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(User::class);
        $accountId = (int) $request->getAttribute('account', 0);
        $zoneId = (int) $request->getAttribute('zone', 0);
        $recordId = (string) $request->getAttribute('record', '');
        $back = '/accounts/' . $accountId . '/zones/' . $zoneId;
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);
        if (!$user instanceof User || !$guard instanceof CsrfGuardInterface || !$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }
        try {
            $this->dns->deleteRecord($user, $accountId, $zoneId, $recordId);
            $actor = $request->getAttribute('actor_user');
            $this->audit->recordRecordDelete($request, $actor instanceof User ? $actor->id : $user->id, $accountId, (string) $zoneId, $recordId, '');
        } catch (AuthorizationException) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('records.error.delete-denied')));
        } catch (\Throwable) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('records.error.delete-failed')));
        }
        return new RedirectResponse($back);
    }
}
