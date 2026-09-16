<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

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
use TowerDNS\Application\Services\DNSManagementService;
use TowerDNS\Application\Validation\RecordInputFilter;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;

/**
 * POST /zones/{provider}/{zone}/records — creates a new DNS record.
 */
final readonly class RecordCreateHandler implements RequestHandlerInterface
{
    public function __construct(
        private DNSManagementService $dns,
        private AuditLogService      $audit,
        private TranslatorInterface  $translator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user       = $request->getAttribute(User::class);
        $providerId = (string) $request->getAttribute('provider', '');
        $zoneId     = (string) $request->getAttribute('zone', '');

        $back = '/zones/' . rawurlencode($providerId) . '/' . rawurlencode($zoneId);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }

        $name    = trim((string) ($body['name'] ?? ''));
        $typeRaw = strtoupper(trim((string) ($body['type'] ?? '')));
        $ttl     = max(1, (int) ($body['ttl'] ?? 300));
        $content = trim((string) ($body['content'] ?? ''));
        $comment = trim((string) ($body['comment'] ?? ''));

        $recordFilter = new RecordInputFilter();
        $recordFilter->setData([
            'name'    => $name,
            'type'    => $typeRaw,
            'ttl'     => $ttl,
            'content' => $content,
        ]);
        if (!$recordFilter->isValid()) {
            return new RedirectResponse($back . '?error=' . rawurlencode(
                $this->translator->translate('records.error.invalid-input'),
            ));
        }
        $fv      = $recordFilter->getValues();
        $name    = trim((string) ($fv['name'] ?? ''));
        $typeRaw = strtoupper(trim((string) ($fv['type'] ?? '')));
        $ttl     = max(1, (int) ($fv['ttl'] ?? 300));
        $content = trim((string) ($fv['content'] ?? ''));

        $type = RecordType::tryFrom($typeRaw);
        if ($type === null) {
            return new RedirectResponse($back . '?error=' . rawurlencode(
                $this->translator->translate('records.error.unsupported-type'),
            ));
        }

        $record = new Record(
            id: '',
            zoneId: $zoneId,
            name: $name,
            type: $type,
            ttl: $ttl,
            content: $content,
            comment: $comment !== '' ? $comment : null,
        );

        try {
            $created = $this->dns->createRecord($user, $providerId, $record);
            $this->audit->recordRecordCreate($request, $user->id, null, $zoneId, $created->name, $created->type->value);
        } catch (AuthorizationException) {
            return new RedirectResponse($back . '?error=' . rawurlencode(
                $this->translator->translate('records.error.create-denied'),
            ));
        } catch (\Throwable) {
            return new RedirectResponse($back . '?error=' . rawurlencode(
                $this->translator->translate('records.error.create-failed'),
            ));
        }

        return new RedirectResponse($back);
    }
}
