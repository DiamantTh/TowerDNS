<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Thin wrapper around Symfony Mailer.
 *
 * DSN examples:
 *   null://null            — no-op (production default until SMTP is configured)
 *   smtp://user:pass@host:587
 *   sendmail://default
 */
final class MailService
{
    private readonly MailerInterface $mailer;

    public function __construct(
        string $dsn,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
        $transport    = Transport::fromDsn($dsn);
        $this->mailer = new Mailer($transport);
    }

    /**
     * Sends an HTML email, with an optional plain-text alternative.
     */
    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        string $textBody = '',
    ): void {
        $email = (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($to)
            ->subject($subject)
            ->html($htmlBody);

        if ($textBody !== '') {
            $email->text($textBody);
        }

        $this->mailer->send($email);
    }
}
