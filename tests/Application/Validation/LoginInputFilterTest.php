<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Validation;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Validation\LoginInputFilter;

final class LoginInputFilterTest extends TestCase
{
    private function filter(): LoginInputFilter
    {
        return new LoginInputFilter();
    }

    // ── valid cases ───────────────────────────────────────────────────────────

    public function testValidEmailAndPasswordPassesValidation(): void
    {
        $f = $this->filter();
        $f->setData(['email' => 'user@example.com', 'password' => 'secret123']);
        self::assertTrue($f->isValid());
    }

    public function testEmailIsNormalised(): void
    {
        $f = $this->filter();
        $f->setData(['email' => '  USER@EXAMPLE.COM  ', 'password' => 'x']);
        self::assertTrue($f->isValid());
        self::assertSame('user@example.com', $f->getValue('email'));
    }

    // ── invalid cases ─────────────────────────────────────────────────────────

    public function testEmptyEmailFailsValidation(): void
    {
        $f = $this->filter();
        $f->setData(['email' => '', 'password' => 'secret']);
        self::assertFalse($f->isValid());
        self::assertArrayHasKey('email', $f->getMessages());
    }

    public function testEmptyPasswordFailsValidation(): void
    {
        $f = $this->filter();
        $f->setData(['email' => 'user@example.com', 'password' => '']);
        self::assertFalse($f->isValid());
        self::assertArrayHasKey('password', $f->getMessages());
    }

    public function testInvalidEmailFailsValidation(): void
    {
        $f = $this->filter();
        $f->setData(['email' => 'not-an-email', 'password' => 'secret']);
        self::assertFalse($f->isValid());
        self::assertArrayHasKey('email', $f->getMessages());
    }

    public function testMissingFieldsFailsValidation(): void
    {
        $f = $this->filter();
        $f->setData([]);
        self::assertFalse($f->isValid());
    }
}
