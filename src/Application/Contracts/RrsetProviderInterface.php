<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

use TowerDNS\Domain\DNS\Rrset;

/** Stable DNS write contract for modern provider implementations. */
interface RrsetProviderInterface
{
    /** @return list<Rrset> */
    public function listRrsets(string $zoneId): array;

    public function replaceRrset(Rrset $rrset): Rrset;

    public function deleteRrset(string $zoneId, string $ownerName, string $type): void;
}
