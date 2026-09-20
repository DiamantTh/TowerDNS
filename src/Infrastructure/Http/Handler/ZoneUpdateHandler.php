<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

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
use TowerDNS\Application\Validation\RecordInputFilter;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;

/** Updates a record in one managed zone. */
final readonly class ZoneUpdateHandler implements RequestHandlerInterface
{
    public function __construct(private ManagedZoneDNSService $dns, private AuditLogService $audit, private TranslatorInterface $translator) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user      = $request->getAttribute(User::class);
        $accountId = (int) $request->getAttribute('account', 0);
        $zoneId    = (int) $request->getAttribute('zone', 0);
        $recordId  = (string) $request->getAttribute('record', '');
        $back      = '/accounts/' . $accountId . '/zones/' . $zoneId;
        if (!$user instanceof User) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('http.error.forbidden')));
        }
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body  = (array) ($request->getParsedBody() ?? []);
        if (!$guard instanceof CsrfGuardInterface || !$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('http.error.invalid-request')));
        }
        $typeRaw = strtoupper(trim((string) ($body['type'] ?? '')));
        if ($typeRaw === '') {
            $typeRaw = $this->dns->findRecordForUpdate($user, $accountId, $zoneId, $recordId)?->type->value ?? '';
        }
        $filter = new RecordInputFilter();
        $filter->setData(['name' => trim((string) ($body['name'] ?? '')), 'type' => $typeRaw, 'ttl' => max(1, (int) ($body['ttl'] ?? 300)), 'content' => trim((string) ($body['content'] ?? ''))]);
        if (!$filter->isValid() || ($type = RecordType::tryFrom($typeRaw)) === null) {
            return new RedirectResponse($back . '/records/' . rawurlencode($recordId) . '/edit?error=' . rawurlencode($this->translator->translate('records.error.invalid-input')));
        }
        $value  = $filter->getValues();
        $record = new Record($recordId, '', (string) $value['name'], $type, (int) $value['ttl'], (string) $value['content'], ($comment = trim((string) ($body['comment'] ?? ''))) !== '' ? $comment : null);
        try {
            $this->dns->updateRecord($user, $accountId, $zoneId, $record);
            $actor = $request->getAttribute('actor_user');
            $this->audit->recordRecordUpdate($request, $actor instanceof User ? $actor->id : $user->id, $accountId, (string) $zoneId, $record->name, $record->type->value);
        } catch (AuthorizationException) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('records.error.update-denied')));
        } catch (\InvalidArgumentException) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('records.error.invalid-input')));
        } catch (\Throwable) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('records.error.update-failed')));
        }
        return new RedirectResponse($back . '?success=' . rawurlencode($this->translator->translate('records.success.updated')));
    }
}
