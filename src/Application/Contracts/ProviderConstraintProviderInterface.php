<?php

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

interface ProviderConstraintProviderInterface
{
    public function constraints(): ProviderConstraintProfile;
}
