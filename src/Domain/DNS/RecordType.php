<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\DNS;

enum RecordType: string
{
    case A      = 'A';
    case AAAA   = 'AAAA';
    case CNAME  = 'CNAME';
    case MX     = 'MX';
    case TXT    = 'TXT';
    case NS     = 'NS';
    case SRV    = 'SRV';
    case CAA    = 'CAA';
    case PTR    = 'PTR';
    case SOA    = 'SOA';
    case DS     = 'DS';
    case DNSKEY = 'DNSKEY';
    case RRSIG  = 'RRSIG';
    case NSEC   = 'NSEC';
}
