<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider;

use TowerDNS\Application\DNS\RdataCanonicalizer;
use TowerDNS\Application\DTO\RrsetMutationCapabilities;
use TowerDNS\Domain\DNS\DNSRecordType;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;

/** Targeted CRUD over native record identities; this is not a provider CAS. */
trait TargetedRrsetMutationTrait
{
    abstract public function listRecords(string $zoneId): array;

    abstract public function createRecord(Record $record): Record;

    abstract public function deleteRecord(string $zoneId, string $recordId): void;

    public function rrsetMutationCapabilities(): RrsetMutationCapabilities
    {
        return new RrsetMutationCapabilities(
            appendRecord: true,
            removeRecord: true,
            consistency: 'targeted_record_ids_no_cas',
        );
    }

    public function appendRrsetRecord(string $zoneId, string $owner, string $type, int $ttl, string $rdata): void
    {
        $recordType = RecordType::tryFrom(strtoupper($type));
        if ($recordType === null) {
            throw new \InvalidArgumentException('Provider does not support this DNS record type.');
        }
        $canonicalType = DNSRecordType::parse($type);
        $wanted        = RdataCanonicalizer::canonicalize($canonicalType, $rdata);
        foreach ($this->listRecords($zoneId) as $record) {
            if ($record->type                                                         === $recordType
                && strcasecmp(rtrim($record->name, '.'), rtrim($owner, '.'))          === 0
                && RdataCanonicalizer::canonicalize($canonicalType, $record->content) === $wanted) {
                return;
            }
        }
        $this->createRecord(new Record('', $zoneId, $owner, $recordType, $ttl, $wanted));
    }

    public function removeRrsetRecord(string $zoneId, string $owner, string $type, string $rdata): void
    {
        $recordType = RecordType::tryFrom(strtoupper($type));
        if ($recordType === null) {
            throw new \InvalidArgumentException('Provider does not support this DNS record type.');
        }
        $canonicalType = DNSRecordType::parse($type);
        $wanted        = RdataCanonicalizer::canonicalize($canonicalType, $rdata);
        foreach ($this->listRecords($zoneId) as $record) {
            if ($record->type                                                         === $recordType
                && strcasecmp(rtrim($record->name, '.'), rtrim($owner, '.'))          === 0
                && RdataCanonicalizer::canonicalize($canonicalType, $record->content) === $wanted) {
                $this->deleteRecord($zoneId, $record->id);
            }
        }
    }
}
