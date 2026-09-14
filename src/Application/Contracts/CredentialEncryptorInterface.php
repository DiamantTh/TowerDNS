<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

/** Minimal encryption boundary for credential-owning application services. */
interface CredentialEncryptorInterface
{
    public function encrypt(string $plaintext): string;

    /** @param string $plaintext plaintext is cleared before this method returns */
    public function wipe(string &$plaintext): void;
}
