<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use OTPHP\TOTP;
use Psr\Clock\ClockInterface;

/**
 * TOTP second-factor service.
 *
 * Uses SHA-256, 8 digits, 30-second period and a 32-byte secret for
 * stronger security than the RFC 6238 baseline defaults.
 *
 * Configuration:
 *   - digits:      8
 *   - algorithm:   sha256
 *   - period:      30 seconds
 *   - secretBytes: 32 bytes  (base32-encoded = 56 chars)
 *   - window:      1         (±1 period tolerance for clock skew)
 */
final class TotpService
{
    private const DIGITS       = 8;
    private const ALGORITHM    = 'sha256';
    private const PERIOD       = 30;
    private const SECRET_BYTES = 32;
    private const WINDOW       = 1;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    /**
     * Generates a new random base32-encoded TOTP secret.
     */
    public function generateSecret(): string
    {
        // Use TOTP::generate() then extract the secret so we stay on the
        // library's internal encoding without pulling in an extra dep.
        $totp = TOTP::generate($this->clock, self::SECRET_BYTES);
        return $totp->getSecret();
    }

    /**
     * Returns the otpauth:// provisioning URI for QR-code generation.
     */
    public function getProvisioningUri(string $secret, string $accountLabel, string $issuer): string
    {
        if ($accountLabel === '' || $issuer === '') {
            throw new \InvalidArgumentException('accountLabel and issuer must not be empty.');
        }
        $totp = $this->buildTotp($secret);
        $totp->setLabel($accountLabel);
        $totp->setIssuer($issuer);

        return $totp->getProvisioningUri();
    }

    /**
     * Verifies a TOTP code against the stored secret.
     *
     * Accepts codes from the previous, current and next window period
     * to tolerate minor clock skew between the server and the user's device.
     *
     * @param string $code   The 8-digit code supplied by the user.
     * @param string $secret The base32-encoded secret stored for the user.
     */
    public function verify(string $code, string $secret): bool
    {
        if ($code === '' || $secret === '') {
            return false;
        }
        try {
            $totp = $this->buildTotp($secret);
            return $totp->verify($code, null, self::WINDOW);
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildTotp(string $secret): TOTP
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('TOTP secret must not be empty.');
        }
        /** @var non-empty-string $secret */
        $totp = TOTP::createFromSecret($secret, $this->clock);
        $totp->setDigits(self::DIGITS);
        $totp->setDigest(self::ALGORITHM);
        $totp->setPeriod(self::PERIOD);

        return $totp;
    }
}
