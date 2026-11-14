<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\DTO;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\DTO\ProviderConnectionVerification;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class ProviderConnectionVerificationTest extends TestCase
{
    public function testSuccessAndFailureExposeOnlyStableCategories(): void
    {
        $success = ProviderConnectionVerification::success();
        self::assertTrue($success->succeeded());
        self::assertSame('success', $success->category);
        self::assertNull($success->httpStatus);

        $failure = ProviderConnectionVerification::failure(ProviderConnectionVerification::AUTHENTICATION_FAILED, 401);
        self::assertFalse($failure->succeeded());
        self::assertSame('authentication_failed', $failure->category);
        self::assertSame(401, $failure->httpStatus);
        self::assertObjectNotHasProperty('message', $failure);
    }

    public function testUnknownCategoriesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProviderConnectionVerification::failure('credentials:' . 'do-not-expose');
    }
}
