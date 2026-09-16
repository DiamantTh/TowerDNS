<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Exception;

/** Stable application failure for managed-zone membership operations. */
final class ZoneMembershipException extends \RuntimeException
{
    public const string MANAGED_ZONE_NOT_FOUND = 'managed_zone_not_found';
    public const string TARGET_USER_NOT_FOUND  = 'target_user_not_found';
    public const string MEMBERSHIP_NOT_FOUND   = 'membership_not_found';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Managed-zone membership operation failed: ' . $reason);
    }
}
