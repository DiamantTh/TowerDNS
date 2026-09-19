<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

/** Result of a one-time invitation registration. */
final readonly class AccountInvitationRegistrationResult
{
    public function __construct(
        public string $userId,
        public int $personalAccountId,
        public int $organizationAccountId,
    ) {}
}
