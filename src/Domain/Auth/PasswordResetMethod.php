<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Auth;

/**
 * Describes the origin of a password reset without putting method-specific
 * behaviour into the reset-token persistence model.
 */
enum PasswordResetMethod: string
{
    case EMAIL_LINK     = 'email_link';
    case SELF_SERVICE   = 'self_service';
    case ADMIN_SET      = 'admin_set';
    case TEMPORARY_CODE = 'temporary_code';
    case RECOVERY_CODE  = 'recovery_code';
}
