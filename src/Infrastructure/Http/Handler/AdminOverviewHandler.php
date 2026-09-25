<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\PageUrls;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

/** A permission-filtered directory of existing administrative handlers. */
final readonly class AdminOverviewHandler implements RequestHandlerInterface
{
    public function __construct(private TemplateRendererInterface $renderer, private AuthorizationService $authz, private PageUrls $urls) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(User::class);
        if (!$user instanceof User) {
            return new HtmlResponse('', 403);
        }

        $areas = [];
        foreach ([
            ['key' => 'navigation.users', 'href' => $this->urls->page('users'), 'permission' => Permission::USER_MANAGE],
            ['key' => 'navigation.roles', 'href' => $this->urls->page('roles'), 'permission' => Permission::ROLE_MANAGE],
            ['key' => 'navigation.settings', 'href' => $this->urls->page('settings'), 'permission' => Permission::SYSTEM_SETTINGS_MANAGE],
            ['key' => 'navigation.schema', 'href' => $this->urls->page('schema'), 'permission' => Permission::SYSTEM_SCHEMA_MANAGE],
            ['key' => 'navigation.providers', 'href' => '/credentials', 'permission' => Permission::PROVIDER_CONFIG_MANAGE],
            ['key' => 'navigation.admin-switch', 'href' => '/admin/switch', 'permission' => Permission::SYSTEM_IMPERSONATION_EXECUTE],
        ] as $area) {
            if ($this->authz->isGranted($user, $area['permission'])) {
                $areas[] = ['key' => $area['key'], 'href' => $area['href']];
            }
        }

        return new HtmlResponse($this->renderer->render('app::admin', ['user' => $user, 'areas' => $areas]));
    }
}
