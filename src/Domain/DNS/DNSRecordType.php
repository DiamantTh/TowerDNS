<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\DNS;

/**
 * A DNS RR type in its canonical presentation form.
 *
 * Known mnemonics and RFC 3597 TYPE#### notation are both accepted.  This is
 * deliberately a value object rather than an enum: DNS type allocation is not
 * closed and a control panel must not make unknown records disappear.
 */
final readonly class DNSRecordType
{
    /** @var array<string, int> */
    private const array KNOWN = [
        'A'     => 1, 'NS' => 2, 'CNAME' => 5, 'SOA' => 6, 'PTR' => 12,
        'MX'    => 15, 'TXT' => 16, 'AAAA' => 28, 'SRV' => 33, 'DS' => 43,
        'SSHFP' => 44, 'RRSIG' => 46, 'NSEC' => 47, 'DNSKEY' => 48,
        'TLSA'  => 52, 'SVCB' => 64, 'HTTPS' => 65, 'CAA' => 257,
    ];

    private function __construct(
        public string $presentation,
        public int $code,
        public bool $isKnown,
    ) {}

    public static function parse(string $value): self
    {
        $value = strtoupper(trim($value));
        if (isset(self::KNOWN[$value])) {
            return new self($value, self::KNOWN[$value], true);
        }

        if (preg_match('/^TYPE([0-9]{1,5})$/D', $value, $matches) !== 1) {
            throw new \InvalidArgumentException(sprintf('Ungültiger DNS-Record-Typ: %s', $value));
        }

        $code = (int) $matches[1];
        if ($code > 65535) {
            throw new \InvalidArgumentException(sprintf('DNS-Record-Typ außerhalb des Bereichs: %s', $value));
        }

        return new self('TYPE' . $code, $code, false);
    }

    public static function fromCode(int $code): self
    {
        if ($code < 0 || $code > 65535) {
            throw new \InvalidArgumentException('DNS-Record-Type-Code muss zwischen 0 und 65535 liegen.');
        }
        $name = array_search($code, self::KNOWN, true);
        return is_string($name) ? new self($name, $code, true) : new self('TYPE' . $code, $code, false);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
