<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Exception;

/** Stable application failure for tenant provider-account operations. */
final class ProviderAccountException extends \RuntimeException
{
    public const string ACCOUNT_NOT_FOUND         = 'account_not_found';
    public const string PROVIDER_NOT_FOUND        = 'provider_not_found';
    public const string PROVIDER_NOT_USER_MANAGED = 'provider_not_user_managed';
    public const string NAME_REQUIRED             = 'name_required';
    public const string CREDENTIALS_INCOMPLETE    = 'credentials_incomplete';
    public const string INSECURE_ENDPOINT         = 'insecure_endpoint';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Provider account operation failed: ' . $reason);
    }
}
