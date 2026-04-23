<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

/**
 * Catalogue of provider capabilities.
 *
 * Provider adapters declare which of these they support via
 * {@see ProviderCapabilitySet}. The Application layer queries the catalogue
 * to gate workflows, so providers are never reduced to a lowest common
 * denominator: every adapter advertises only what it can actually deliver.
 */
final class Capability
{
    public const ZONE_LIST   = 'zone.list';
    public const ZONE_READ   = 'zone.read';
    public const ZONE_CREATE = 'zone.create';
    public const ZONE_UPDATE = 'zone.update';
    public const ZONE_DELETE = 'zone.delete';

    public const RECORD_LIST    = 'record.list';
    public const RECORD_CREATE  = 'record.create';
    public const RECORD_UPDATE  = 'record.update';
    public const RECORD_DELETE  = 'record.delete';
    public const RECORD_COMMENT = 'record.comment';

    public const DNSSEC_STATUS_READ      = 'dnssec.status.read';
    public const DNSSEC_AUTO_MANAGED     = 'dnssec.auto_managed';
    public const DNSSEC_ACTION_EXECUTE   = 'dnssec.action.execute';
    public const DNSSEC_KEY_LIST         = 'dnssec.key.list';
    public const DNSSEC_KEY_ROLLOVER     = 'dnssec.key.rollover';
    public const DNSSEC_DS_READ          = 'dnssec.ds.read';

    public const PROVIDER_CREDENTIALS_MANAGE = 'provider.credentials.manage';

    private function __construct()
    {
    }
}
