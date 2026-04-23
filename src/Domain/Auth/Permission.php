<?php

declare(strict_types=1);

namespace TowerDNS\Domain\Auth;

enum Permission: string
{
    case ZONE_READ = 'zone.read';
    case ZONE_CREATE = 'zone.create';
    case ZONE_UPDATE = 'zone.update';
    case ZONE_DELETE = 'zone.delete';

    case RECORD_READ = 'record.read';
    case RECORD_CREATE = 'record.create';
    case RECORD_UPDATE = 'record.update';
    case RECORD_DELETE = 'record.delete';

    case DNSSEC_STATUS_READ = 'dnssec.status.read';
    case DNSSEC_ACTION_EXECUTE = 'dnssec.action.execute';

    case PROVIDER_CREDENTIALS_MANAGE = 'provider.credentials.manage';
    case PROVIDER_CONFIG_MANAGE = 'provider.config.manage';

    case USER_MANAGE = 'user.manage';
    case ROLE_MANAGE = 'role.manage';

    case SYSTEM_SETTINGS_MANAGE = 'system.settings.manage';
}
