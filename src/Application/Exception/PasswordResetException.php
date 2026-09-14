<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Exception;

/** A reset token cannot be consumed safely. */
final class PasswordResetException extends \RuntimeException {}
