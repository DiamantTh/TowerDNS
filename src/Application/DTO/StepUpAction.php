<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

final class StepUpAction
{
    public const string IAM_USER_ROLES    = 'iam.user.roles';
    public const string IAM_USER_STATUS   = 'iam.user.status';
    public const string IAM_USER_PASSWORD = 'iam.user.password';
    public const string IAM_USER_DELETE   = 'iam.user.delete';
    public const string IAM_ROLE_CREATE   = 'iam.role.create';
    public const string IAM_ROLE_SAVE     = 'iam.role.save';
    public const string IAM_ROLE_DELETE   = 'iam.role.delete';
    public const string ADMIN_SWITCH      = 'admin.switch.start';

    public static function adminSwitchTarget(string $userId, ?int $accountId): string
    {
        return hash('sha256', json_encode([$userId, $accountId], JSON_THROW_ON_ERROR));
    }

    private function __construct() {}
}
