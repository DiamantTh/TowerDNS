<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

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
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationManager;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationStatus;

/** Authenticated administrator UI for controlled schema upgrades. */
final readonly class SchemaMigrationHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private AuthorizationService $authorization,
        private SchemaMigrationManager $migrations,
        private TranslatorInterface $translator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(User::class);
        if (!$user instanceof User) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }

        try {
            $this->authorization->assert($user, Permission::SYSTEM_SCHEMA_MANAGE);
        } catch (AuthorizationException) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }

        /** @var CsrfGuardInterface|null $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        if (!$guard instanceof CsrfGuardInterface) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }

        $error   = null;
        $success = null;
        if ($request->getMethod() === 'POST') {
            $body = (array) ($request->getParsedBody() ?? []);
            if (!$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
                $error = $this->translator->translate('schema.error.invalid-csrf');
            } else {
                try {
                    $this->migrations->migrate();
                    $success = $this->translator->translate('schema.success.updated');
                } catch (\Throwable) {
                    $error = $this->translator->translate('schema.error.failed');
                }
            }
        }

        try {
            $status = $this->migrations->status();
        } catch (\Throwable) {
            $status = new SchemaMigrationStatus(false, false, false, [], [], ['schema inspection failed'], []);
            $error ??= $this->translator->translate('schema.error.failed');
        }

        return new HtmlResponse($this->renderer->render('app::settings/schema', [
            'user'      => $user,
            'status'    => $status->toArray(),
            'csrfToken' => $guard->generateToken(),
            'error'     => $error,
            'success'   => $success,
        ]));
    }
}
