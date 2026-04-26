<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Auth;

/**
 * Strict, fine-grained permission catalogue.
 *
 * Permissions are checked centrally by the Application layer (see
 * {@see \TowerDNS\Application\Services\AuthorizationService}); the UI never
 * acts as the sole gate. Sensitive areas (DNSSEC actions, provider
 * credentials, user/role administration, system settings) each have
 * dedicated permissions so they can be granted independently.
 */
enum Permission: string
{
    case ZONE_LIST   = 'zone.list';
    case ZONE_READ   = 'zone.read';
    case ZONE_CREATE = 'zone.create';
    case ZONE_UPDATE = 'zone.update';
    case ZONE_DELETE = 'zone.delete';

    case RECORD_READ   = 'record.read';
    case RECORD_CREATE = 'record.create';
    case RECORD_UPDATE = 'record.update';
    case RECORD_DELETE = 'record.delete';

    case DNSSEC_STATUS_READ    = 'dnssec.status.read';
    case DNSSEC_ACTION_EXECUTE = 'dnssec.action.execute';

    case PROVIDER_CREDENTIALS_MANAGE = 'provider.credentials.manage';
    case PROVIDER_CONFIG_MANAGE      = 'provider.config.manage';

    case USER_MANAGE = 'user.manage';
    case ROLE_MANAGE = 'role.manage';

    case SYSTEM_SETTINGS_MANAGE = 'system.settings.manage';
}
