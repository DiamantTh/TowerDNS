<?php

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

use TowerDNS\Application\DTO\DnssecValidationResult;

interface DnssecDiagnosticInterface
{
    public function validateTlsa(string $ownerFqdn, string $zoneName): DnssecValidationResult;

    /** @return array<string, mixed> */
    public function checkSmtpDane(string $domain): array;
}
