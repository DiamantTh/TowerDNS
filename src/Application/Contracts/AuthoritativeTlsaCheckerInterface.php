<?php

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

use TowerDNS\Application\DTO\AuthoritativeTlsaCheck;

interface AuthoritativeTlsaCheckerInterface
{
    /** @param list<string> $expected */
    public function check(string $ownerFqdn, string $zoneName, array $expected, ?int $expectedTtl): AuthoritativeTlsaCheck;
}
