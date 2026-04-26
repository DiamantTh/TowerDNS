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
    public const string ZONE_LIST   = 'zone.list';
    public const string ZONE_READ   = 'zone.read';
    public const string ZONE_CREATE = 'zone.create';
    public const string ZONE_UPDATE = 'zone.update';
    public const string ZONE_DELETE = 'zone.delete';

    public const string RECORD_LIST    = 'record.list';
    public const string RECORD_CREATE  = 'record.create';
    public const string RECORD_UPDATE  = 'record.update';
    public const string RECORD_DELETE  = 'record.delete';
    public const string RECORD_COMMENT = 'record.comment';

    public const string DNSSEC_STATUS_READ    = 'dnssec.status.read';
    public const string DNSSEC_AUTO_MANAGED   = 'dnssec.auto_managed';
    public const string DNSSEC_ACTION_EXECUTE = 'dnssec.action.execute';
    public const string DNSSEC_KEY_LIST       = 'dnssec.key.list';
    public const string DNSSEC_KEY_ROLLOVER   = 'dnssec.key.rollover';
    public const string DNSSEC_DS_READ        = 'dnssec.ds.read';

    public const string PROVIDER_CREDENTIALS_MANAGE = 'provider.credentials.manage';

    private function __construct() {}
}
