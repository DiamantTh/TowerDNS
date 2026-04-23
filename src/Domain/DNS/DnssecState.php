<?php

declare(strict_types=1);

namespace TowerDNS\Domain\DNS;

enum DnssecState: string
{
    case UNSIGNED = 'unsigned';
    case SIGNED = 'signed';
    case PARTIAL = 'partial';
    case UNKNOWN = 'unknown';
}
