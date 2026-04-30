<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Validation;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Validation\RecordInputFilter;

final class RecordInputFilterTest extends TestCase
{
    private function filter(): RecordInputFilter
    {
        return new RecordInputFilter();
    }

    /** @return array<string, string> */
    private function valid(): array
    {
        return [
            'name'    => 'www.example.com',
            'type'    => 'A',
            'ttl'     => '3600',
            'content' => '192.0.2.1',
        ];
    }

    // ── valid cases ───────────────────────────────────────────────────────────

    public function testCompleteValidDataPassesValidation(): void
    {
        $f = $this->filter();
        $f->setData($this->valid());
        self::assertTrue($f->isValid());
    }

    public function testMinimumTtlBoundaryIsAccepted(): void
    {
        $f = $this->filter();
        $f->setData(array_merge($this->valid(), ['ttl' => '30']));
        self::assertTrue($f->isValid());
    }

    public function testMaximumTtlBoundaryIsAccepted(): void
    {
        $f = $this->filter();
        $f->setData(array_merge($this->valid(), ['ttl' => '604800']));
        self::assertTrue($f->isValid());
    }

    // ── TTL boundary violations ───────────────────────────────────────────────

    public function testTtlBelowMinimumFailsValidation(): void
    {
        $f = $this->filter();
        $f->setData(array_merge($this->valid(), ['ttl' => '29']));
        self::assertFalse($f->isValid());
        self::assertArrayHasKey('ttl', $f->getMessages());
    }

    public function testTtlAboveMaximumFailsValidation(): void
    {
        $f = $this->filter();
        $f->setData(array_merge($this->valid(), ['ttl' => '604801']));
        self::assertFalse($f->isValid());
        self::assertArrayHasKey('ttl', $f->getMessages());
    }

    // ── required fields ───────────────────────────────────────────────────────

    public function testEmptyNameFailsValidation(): void
    {
        $f = $this->filter();
        $f->setData(array_merge($this->valid(), ['name' => '']));
        self::assertFalse($f->isValid());
        self::assertArrayHasKey('name', $f->getMessages());
    }

    public function testEmptyTypeFailsValidation(): void
    {
        $f = $this->filter();
        $f->setData(array_merge($this->valid(), ['type' => '']));
        self::assertFalse($f->isValid());
        self::assertArrayHasKey('type', $f->getMessages());
    }

    public function testEmptyContentFailsValidation(): void
    {
        $f = $this->filter();
        $f->setData(array_merge($this->valid(), ['content' => '']));
        self::assertFalse($f->isValid());
        self::assertArrayHasKey('content', $f->getMessages());
    }

    public function testMissingAllFieldsFailsValidation(): void
    {
        $f = $this->filter();
        $f->setData([]);
        self::assertFalse($f->isValid());
    }
}
