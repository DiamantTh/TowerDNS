<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Module;

use TowerDNS\Domain\Auth\PermissionDefinition;

/** Declares technical permissions contributed by an active local module. */
interface PermissionContributorInterface extends TowerDNSModuleInterface
{
    /** @return iterable<PermissionDefinition> */
    public function permissionDefinitions(): iterable;
}
