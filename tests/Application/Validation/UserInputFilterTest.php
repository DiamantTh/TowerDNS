<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Validation;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Validation\UserInputFilter;

final class UserInputFilterTest extends TestCase
{
    private function filter(): UserInputFilter
    {
        return new UserInputFilter();
    }

    // ── valid cases ───────────────────────────────────────────────────────────

    public function testValidEmailAndPasswordPassesValidation(): void
    {
        $f = $this->filter();
        $f->setData(['email' => 'admin@example.org', 'password' => 'StrongPassword!42']);
        self::assertTrue($f->isValid());
    }

    public function testEmailIsNormalisedToLowerCase(): void
    {
        $f = $this->filter();
        $f->setData(['email' => '  ADMIN@EXAMPLE.ORG  ', 'password' => 'pass']);
        self::assertTrue($f->isValid());
        self::assertSame('admin@example.org', $f->getValue('email'));
    }

    public function testMinimalPasswordLengthIsAccepted(): void
    {
        $f = $this->filter();
        $f->setData(['email' => 'a@b.de', 'password' => 'x']);
        self::assertTrue($f->isValid());
    }

    public function testMaximumPasswordLengthIsAccepted(): void
    {
        $f = $this->filter();
        $f->setData(['email' => 'a@b.de', 'password' => str_repeat('a', 1024)]);
        self::assertTrue($f->isValid());
    }

    // ── invalid cases ─────────────────────────────────────────────────────────

    public function testEmptyEmailFailsValidation(): void
    {
        $f = $this->filter();
        $f->setData(['email' => '', 'password' => 'secure']);
        self::assertFalse($f->isValid());
        self::assertArrayHasKey('email', $f->getMessages());
    }

    public function testInvalidEmailFailsValidation(): void
    {
        $f = $this->filter();
        $f->setData(['email' => 'not-an-email', 'password' => 'secure']);
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

    public function testTooLongPasswordFailsValidation(): void
    {
        $f = $this->filter();
        $f->setData(['email' => 'user@example.com', 'password' => str_repeat('x', 1025)]);
        self::assertFalse($f->isValid());
        self::assertArrayHasKey('password', $f->getMessages());
    }

    public function testMissingDataFailsValidation(): void
    {
        $f = $this->filter();
        $f->setData([]);
        self::assertFalse($f->isValid());
    }
}
