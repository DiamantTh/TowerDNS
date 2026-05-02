<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

/**
 * Generates cryptographically secure random passwords with guaranteed
 * character-class coverage (lower, upper, digit, symbol).
 *
 * Uses random_int() (CSPRNG). Result is shuffled with a Fisher-Yates pass
 * driven by random_int() to avoid predictable class positions.
 *
 * Optionally validates the produced password against the configured
 * PasswordPolicy and retries until it passes (bounded).
 */
final readonly class PasswordGenerator
{
    private const string LOWER  = 'abcdefghijkmnopqrstuvwxyz';   // no l
    private const string UPPER  = 'ABCDEFGHJKLMNPQRSTUVWXYZ';    // no I, O
    private const string DIGIT  = '23456789';                    // no 0, 1
    private const string SYMBOL = '!@#$%^&*-_=+?';

    private const int MIN_LENGTH    = 12;
    private const int MAX_LENGTH    = 128;
    private const int MAX_RETRIES   = 8;

    public function __construct(private ?PasswordPolicy $policy = null) {}

    /**
     * @throws \RuntimeException if no password satisfying the policy can be
     *         generated within the retry budget (should not happen for sane
     *         policies; signals misconfiguration).
     */
    public function generate(int $length = 24): string
    {
        $length = max(self::MIN_LENGTH, min(self::MAX_LENGTH, $length));

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            $candidate = $this->buildOne($length);
            if ($this->policy === null) {
                return $candidate;
            }
            try {
                $this->policy->assertValid($candidate);
                return $candidate;
            } catch (\InvalidArgumentException) {
                // try again with the same length
            }
        }

        throw new \RuntimeException(
            'Could not generate a password satisfying the configured policy after '
            . self::MAX_RETRIES . ' attempts. Check security.password configuration.'
        );
    }

    private function buildOne(int $length): string
    {
        // Guarantee at least one char from each class.
        $chars = [
            $this->pick(self::LOWER),
            $this->pick(self::UPPER),
            $this->pick(self::DIGIT),
            $this->pick(self::SYMBOL),
        ];

        $pool    = self::LOWER . self::UPPER . self::DIGIT . self::SYMBOL;
        $poolMax = strlen($pool) - 1;

        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $pool[random_int(0, $poolMax)];
        }

        // Fisher-Yates shuffle with CSPRNG.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j           = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /**
     * @param non-empty-string $alphabet
     */
    private function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
