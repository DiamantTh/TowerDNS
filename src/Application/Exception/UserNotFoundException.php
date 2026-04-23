<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Exception;

use RuntimeException;

final class UserNotFoundException extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Benutzer mit ID "%s" nicht gefunden.', $id));
    }

    public static function forEmail(string $email): self
    {
        return new self(sprintf('Benutzer mit E-Mail "%s" nicht gefunden.', $email));
    }
}
