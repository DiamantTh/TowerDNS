<?php

declare(strict_types=1);

namespace TowerDNS\Domain\Account;

enum AccountKind: string
{
    case PERSONAL     = 'personal';
    case ORGANIZATION = 'organization';
}
