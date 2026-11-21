<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

/** Determines whether a TLSA key scope can use the provider's actual write operations. */
final class TlsaApiKeyScopePolicy
{
    public function supports(string $scope, bool $conditional, bool $appendRecord, bool $removeRecord): bool
    {
        if ($scope === 'tlsa:read') {
            return true;
        }

        $canAppend = $conditional || $appendRecord;
        $canRemove = $conditional || $removeRecord;

        return match ($scope) {
            'tlsa:append' => $canAppend,
            'tlsa:write'  => $canAppend && $canRemove,
            default       => false,
        };
    }
}
