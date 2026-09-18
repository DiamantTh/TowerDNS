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
use TowerDNS\Application\Validation\RecordInputFilter;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;

/** Creates a record through an account-owned managed zone. */
final readonly class RecordCreateHandler implements RequestHandlerInterface
{
    public function __construct(private ManagedZoneDNSService $dns, private AuditLogService $audit, private TranslatorInterface $translator) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(User::class);
        if (!$user instanceof User) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }
        $accountId     = (int) $request->getAttribute('account', 0);
        $managedZoneId = (int) $request->getAttribute('zone', 0);
        $back          = '/accounts/' . $accountId . '/zones/' . $managedZoneId;
        $guard         = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body          = (array) ($request->getParsedBody() ?? []);
        if (!$guard instanceof CsrfGuardInterface || !$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }
        $filter = new RecordInputFilter();
        $filter->setData(['name' => trim((string) ($body['name'] ?? '')), 'type' => strtoupper(trim((string) ($body['type'] ?? ''))), 'ttl' => max(1, (int) ($body['ttl'] ?? 300)), 'content' => trim((string) ($body['content'] ?? ''))]);
        if (!$filter->isValid()) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('records.error.invalid-input')));
        }
        $value = $filter->getValues();
        $type  = RecordType::tryFrom((string) $value['type']);
        if ($type === null) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('records.error.unsupported-type')));
        }
        $record = new Record('', '', (string) $value['name'], $type, (int) $value['ttl'], (string) $value['content'], ($comment = trim((string) ($body['comment'] ?? ''))) !== '' ? $comment : null);
        try {
            $created = $this->dns->createRecord($user, $accountId, $managedZoneId, $record);
            $actor   = $request->getAttribute('actor_user');
            $this->audit->recordRecordCreate($request, $actor instanceof User ? $actor->id : $user->id, $accountId, (string) $managedZoneId, $created->name, $created->type->value);
        } catch (AuthorizationException) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('records.error.create-denied')));
        } catch (\Throwable) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('records.error.create-failed')));
        }
        return new RedirectResponse($back);
    }
}
