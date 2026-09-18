<?php

declare(strict_types=1);

namespace TowerDNS\Application\Exception;

final class ResourceLimitExceededException extends \DomainException
{
    public const string ZONES             = 'quota_zone_limit_reached';
    public const string MEMBERS           = 'quota_member_limit_reached';
    public const string PROVIDER_ACCOUNTS = 'quota_provider_account_limit_reached';

    public readonly string $codeId;

    public function __construct(string $codeId)
    {
        parent::__construct($codeId);
        $this->codeId = $codeId;
    }
}
