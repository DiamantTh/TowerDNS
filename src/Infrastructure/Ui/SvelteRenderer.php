<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Ui;

use Mezzio\Template\TemplateRendererInterface;
use TowerDNS\Application\Theme\ThemeManager;
use TowerDNS\Domain\Account\ProviderAccount;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;

/** Svelte application shell; no server-side HTML template engine is used. */
final class SvelteRenderer implements TemplateRendererInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $defaults = [];

    public function __construct(
        private readonly ThemeManager $themes,
        private readonly bool $debug = false,
    ) {}

    public function render(string $name, array|object $params = []): string
    {
        $provided    = is_object($params) ? get_object_vars($params) : (array) $params;
        $data        = array_replace($this->defaults[self::TEMPLATE_ALL] ?? [], $this->defaults[$name] ?? [], $provided);
        $user        = $data['user'] ?? $data['currentUser'] ?? null;
        $activeTheme = $this->themes->getActive($user instanceof User ? $user->theme : null);
        $page        = str_starts_with($name, 'app::') ? substr($name, 5) : $name;
        $payload     = [
            'page'   => $page,
            'props'  => $this->normalize($data),
            'themes' => array_map(static fn($theme): array => $theme->toArray(), array_values($this->themes->getAvailable())),
            'theme'  => $activeTheme->toArray(),
            'debug'  => $this->debug,
        ];
        $json  = json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $theme = htmlspecialchars($activeTheme->skeletonTheme, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars($this->titleFor($page), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
            <!doctype html>
            <html lang="de" data-theme="{$theme}">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="color-scheme" content="light dark">
                <title>{$title}</title>
                <script src="/assets/theme-init.bundle.js"></script>
                <link rel="stylesheet" href="/assets/app.css">
            </head>
            <body>
                <div id="towerdns-app"></div>
                <script id="towerdns-page" type="application/json">{$json}</script>
                <script type="module" src="/assets/app.bundle.js"></script>
            </body>
            </html>
            HTML;
    }

    public function addDefaultParam(string $templateName, string $param, mixed $value): void
    {
        $this->defaults[$templateName][$param] = $value;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value instanceof Role) {
            return [
                'id'          => $value->id,
                'name'        => $value->name,
                'isSystem'    => $value->isSystem,
                'permissions' => array_map(static fn(\BackedEnum $permission): string => (string) $permission->value, $value->getPermissions()),
            ];
        }
        if ($value instanceof ProviderAccount) {
            return [
                'id'                 => $value->id,
                'accountId'          => $value->accountId,
                'providerType'       => $value->providerType,
                'name'               => $value->name,
                'credentialsVersion' => $value->credentialsVersion,
                'isActive'           => $value->isActive,
                'createdAt'          => $value->createdAt,
                'lastTestedAt'       => $value->lastTestedAt,
                'lastUsedAt'         => $value->lastUsedAt,
            ];
        }
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && in_array($key, ['source', 'credentialsEncrypted', 'tokenHash', 'password_hash'], true)) {
                    continue;
                }
                $normalized[$key] = $this->normalize($item);
            }
            return $normalized;
        }
        if (is_object($value)) {
            return $this->normalize(get_object_vars($value));
        }
        return null;
    }

    private function titleFor(string $page): string
    {
        return match ($page) {
            'login'           => 'Anmelden — TowerDNS',
            'forgot_password' => 'Passwort vergessen — TowerDNS',
            'reset_password'  => 'Passwort zurücksetzen — TowerDNS',
            'dashboard'       => 'Dashboard — TowerDNS',
            'zones/list'      => 'DNS-Zonen — TowerDNS',
            'settings'        => 'Systemeinstellungen — TowerDNS',
            'profile/index'   => 'Mein Profil — TowerDNS',
            default           => 'TowerDNS',
        };
    }
}
