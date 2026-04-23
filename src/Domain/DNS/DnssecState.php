<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\DNS;

enum DnssecState: string
{
    case UNSIGNED = 'unsigned';
    case SIGNED = 'signed';
    case PARTIAL = 'partial';
    case UNKNOWN = 'unknown';
}
