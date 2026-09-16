<?php

declare(strict_types=1);

namespace TowerDNS\Application\Exception;

final class ResourceLimitExceededException extends \DomainException
{
    public const ZONES             = 'quota_zone_limit_reached';
    public const MEMBERS           = 'quota_member_limit_reached';
    public const PROVIDER_ACCOUNTS = 'quota_provider_account_limit_reached';

    public function __construct(public readonly string $codeId)
    {
        parent::__construct($codeId);
    }
}
