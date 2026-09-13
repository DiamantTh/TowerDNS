<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Module;

use TowerDNS\Application\Auth\ActionGroupDefinition;

/** Declares UI-only action groups contributed by an active local module. */
interface ActionGroupContributorInterface extends TowerDNSModuleInterface
{
    /** @return iterable<ActionGroupDefinition> */
    public function actionGroupDefinitions(): iterable;
}
