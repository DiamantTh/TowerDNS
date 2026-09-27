<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Exception;

final class StepUpRequiredException extends AuthorizationException
{
    public function __construct(
        public readonly string $action,
        public readonly string $targetId,
    ) {
        parent::__construct('A recent MFA/passkey confirmation is required.');
    }
}
