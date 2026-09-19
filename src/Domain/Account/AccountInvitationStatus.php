<?php

declare(strict_types=1);

namespace TowerDNS\Domain\Account;

enum AccountInvitationStatus: string
{
    case PENDING  = 'pending';
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
    case REVOKED  = 'revoked';
    case EXPIRED  = 'expired';
}
