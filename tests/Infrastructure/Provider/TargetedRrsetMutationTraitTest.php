<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Provider;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\TargetedRrsetMutationProviderInterface;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;
use TowerDNS\Infrastructure\Provider\TargetedRrsetMutationTrait;

/** @psalm-api Runtime discovery by PHPUnit is not statically visible. */
final class TargetedRrsetMutationTraitTest extends TestCase
{
    public function testAppendAndRemovalTargetOnlyTheSelectedRdataAndAreRetrySafe(): void
    {
        $provider          = new TargetedMutationRecordingProvider();
        $provider->records = [
            new Record('r1', 'example.org', '_443._tcp', RecordType::TLSA, 600, '3 1 1 aabb'),
            new Record('r2', 'example.org', '_443._tcp', RecordType::TLSA, 600, '3 1 1 ccdd'),
        ];

        $provider->appendRrsetRecord('example.org', '_443._tcp', 'TLSA', 300, '3 1 1 EEFF');
        $provider->appendRrsetRecord('example.org', '_443._tcp', 'TLSA', 300, '3 1 1 eeff');
        self::assertCount(3, $provider->records);
        self::assertSame(
            ['3 1 1 aabb', '3 1 1 ccdd', '3 1 1 eeff'],
            array_map(static fn(Record $record): string => $record->content, $provider->records),
        );

        $provider->removeRrsetRecord('example.org', '_443._tcp', 'TLSA', '3 1 1 aabb');
        $provider->removeRrsetRecord('example.org', '_443._tcp', 'TLSA', '3 1 1 aabb');
        self::assertSame(['3 1 1 ccdd', '3 1 1 eeff'], array_map(static fn(Record $record): string => $record->content, $provider->records));
        self::assertTrue($provider->rrsetMutationCapabilities()->appendRecord);
        self::assertTrue($provider->rrsetMutationCapabilities()->removeRecord);
        self::assertFalse($provider->rrsetMutationCapabilities()->atomicMultiRecord);
    }
}

/** @psalm-api Used through the trait contract in the test. */
final class TargetedMutationRecordingProvider implements TargetedRrsetMutationProviderInterface
{
    use TargetedRrsetMutationTrait;

    /** @var list<Record> */
    public array $records = [];

    /** @return list<Record> */
    #[\Override]
    public function listRecords(string $zoneId): array
    {
        return $this->records;
    }

    #[\Override]
    public function createRecord(Record $record): Record
    {
        $created         = new Record('r' . (count($this->records) + 1), $record->zoneId, $record->name, $record->type, $record->ttl, $record->content);
        $this->records[] = $created;
        return $created;
    }

    #[\Override]
    public function deleteRecord(string $zoneId, string $recordId): void
    {
        $this->records = array_values(array_filter($this->records, static fn(Record $record): bool => $record->id !== $recordId));
    }
}
