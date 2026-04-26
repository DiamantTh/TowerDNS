<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use ZxcvbnPhp\Zxcvbn;

/**
 * Configurable password strength policy using zxcvbn.
 *
 * Configuration (passed via constructor):
 *   minLength  int  8–128,  default 16
 *   minScore   int  0–4,    default 0  (0 = disabled)
 */
final readonly class PasswordPolicy
{
    private const int FLOOR   = 8;
    private const int CEILING = 128;

    private int $minLength;
    private int $minScore;

    public function __construct(int $minLength = 16, int $minScore = 0)
    {
        $this->minLength = max(self::FLOOR, min(self::CEILING, $minLength));
        $this->minScore  = max(0, min(4, $minScore));
    }

    public function getMinLength(): int
    {
        return $this->minLength;
    }

    public function getMinScore(): int
    {
        return $this->minScore;
    }

    /**
     * Validates the password against the configured policy.
     *
     * @throws \InvalidArgumentException when the password is too short or too weak.
     */
    public function assertValid(string $password): void
    {
        if (mb_strlen($password) < $this->minLength) {
            throw new \InvalidArgumentException(
                sprintf('Das Passwort muss mindestens %d Zeichen lang sein.', $this->minLength)
            );
        }

        if ($this->minScore > 0) {
            $result = new Zxcvbn()->passwordStrength($password);
            if ($result['score'] < $this->minScore) {
                $suggestions = $result['feedback']['suggestions'] ?? [];
                $hint        = $suggestions !== [] ? ' ' . implode(' ', $suggestions) : '';
                throw new \InvalidArgumentException('Das Passwort ist zu schwach.' . $hint);
            }
        }
    }

    /**
     * Returns the zxcvbn score (0–4) and feedback for the given password.
     *
     * @return array{score: int, feedback: array<string, mixed>}
     */
    public function score(string $password): array
    {
        /** @var array{score: int, feedback: array<string, mixed>} $result */
        $result = new Zxcvbn()->passwordStrength($password);
        return $result;
    }
}
