<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

final class StepUpAction
{
    public const string IAM_USER_ROLES            = 'iam.user.roles';
    public const string IAM_USER_STATUS           = 'iam.user.status';
    public const string IAM_USER_PASSWORD         = 'iam.user.password';
    public const string IAM_USER_WEBAUTHN_REVOKE  = 'iam.user.webauthn.revoke';
    public const string IAM_USER_ACCOUNT_RECOVERY = 'iam.user.account-recovery';
    public const string IAM_USER_DELETE           = 'iam.user.delete';
    public const string IAM_ROLE_CREATE           = 'iam.role.create';
    public const string IAM_ROLE_SAVE             = 'iam.role.save';
    public const string IAM_ROLE_DELETE           = 'iam.role.delete';
    public const string ADMIN_SWITCH              = 'admin.switch.start';
    public const string PROFILE_WEBAUTHN_ENROLL   = 'profile.webauthn.enroll';
    public const string PROFILE_WEBAUTHN_DELETE   = 'profile.webauthn.delete';
    public const string PROFILE_TOTP_ENROLL       = 'profile.totp.enroll';
    public const string PROFILE_TOTP_DELETE       = 'profile.totp.delete';
    public const string PROFILE_PASSWORD_CHANGE   = 'profile.password.change';
    public const string PROFILE_PASSWORD_DISABLE  = 'profile.password.disable';

    public static function adminSwitchTarget(string $userId, ?int $accountId): string
    {
        return hash('sha256', json_encode([$userId, $accountId], JSON_THROW_ON_ERROR));
    }

    public static function iamUserWebAuthnCredentialTarget(string $userId, string $credentialId): string
    {
        return hash('sha256', $userId . "\0" . hash('sha256', $credentialId));
    }

    /** @psalm-suppress UnusedConstructor This constants/factory class must not be instantiated. */
    private function __construct() {}
}
