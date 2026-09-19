<?php

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

use TowerDNS\Domain\Account\AccountInvitation;

final readonly class AccountInvitationCreationResult
{
    public function __construct(
        public AccountInvitation $invitation,
        public bool $mailDelivered,
    ) {}
}
