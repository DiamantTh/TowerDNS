<?php

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

use TowerDNS\Domain\DNS\Rrset;

/** The provider must reject the entire operation when the expected RRset has changed. */
interface ConditionalRrsetProviderInterface
{
    public function changeRrsetConditionally(?Rrset $before, ?Rrset $after): void;
}
