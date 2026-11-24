<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use TowerDNS\Infrastructure\Persistence\MonitoringAlerts;

/** @psalm-api Registered in the console factory. */
#[AsCommand(name: 'towerdns:monitor:notify', description: 'Deliver due monitoring notifications (max. 20)')]
final class MonitorNotifyCommand extends Command
{
    /**
     * @param array<string,mixed> $config
     * @param null|\Closure(): EsmtpTransport $transportFactory Test seam.
     */
    public function __construct(private readonly MonitoringAlerts $alerts, private readonly array $config, private readonly ?\Closure $transportFactory = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (($this->config['enabled'] ?? false) !== true) {
            $output->writeln('Monitoring mail is disabled.');
            return self::SUCCESS;
        }
        try {
            $transport = $this->transportFactory instanceof \Closure ? ($this->transportFactory)() : $this->transport();
            $from      = $this->address('from_address');
        } catch (\InvalidArgumentException) {
            $output->writeln('Invalid monitoring SMTP configuration.');
            return self::INVALID;
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->alerts->cleanup(gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() - 180 * 86400));
        $this->alerts->queueExpiringKeys($now, gmdate('Y-m-d H:i:s', time() + 14 * 86400));
        $failed = 0;
        foreach ($this->alerts->due($now) as $row) {
            $key = (string) $row['event_key'];
            if (!$this->alerts->claim($key, $now)) {
                continue;
            }
            if (!$this->alerts->canDeliver($key, gmdate('Y-m-d H:i:s'))) {
                $this->alerts->finish($key, 'cancelled', null, $now);
                continue;
            }
            try {
                $transport->start();
            } catch (TransportExceptionInterface) {
                ++$failed;
                $attempt = (int) $row['attempts'] + 1;
                $this->alerts->finish($key, $attempt < 3 ? 'pending' : 'failed', 'smtp_connect', gmdate('Y-m-d H:i:s', time() + 300 * $attempt));
                continue;
            }
            // The SMTP connection can block while an operator changes consent or revokes a key.
            if (!$this->alerts->canDeliver($key, gmdate('Y-m-d H:i:s'))) {
                $this->alerts->finish($key, 'cancelled', null, $now);
                continue;
            }
            $de   = $row['locale'] === 'de-DE';
            $kind = (string) $row['kind'];
            /** @var array<string, string> $messages */
            $messages = $de ? require __DIR__ . '/../../../translations/de-DE.php' : require __DIR__ . '/../../../translations/en-GB.php';
            $event    = $messages['monitoring.status.' . $kind] ?? $kind;
            $message  = new Email()->from($from)->to(new Address((string) $row['recipient']))
                ->subject($de ? 'TowerDNS: Monitoring-Ereignis' : 'TowerDNS: monitoring event')
                ->text(sprintf(
                    "%s: %s\n%s: %s\n%s: %s UTC\n%s: %s\n%s\n",
                    'Zone',
                    (string) $row['canonical_name'],
                    $de ? 'Ereignis' : 'Event',
                    $event,
                    $de ? 'Beobachtet' : 'Observed',
                    (string) $row['occurred_at'],
                    $de ? 'Prüfmethode' : 'Check method',
                    (string) $row['method'],
                    $row['api_key_id'] === null ? $messages['monitoring.description'] : ($de ? 'Ablauf (UTC): ' : 'Expires (UTC): ') . $row['expires_at'],
                ));
            $message->getHeaders()->addIdHeader('Message-ID', $key . '@towerdns.invalid');
            try {
                $transport->send($message);
                $this->alerts->finish($key, 'sent', null, $now);
            } catch (TransportExceptionInterface) {
                ++$failed;
                $this->alerts->finish($key, 'unknown', 'smtp_delivery_uncertain', $now);
            }
        }
        $output->writeln(sprintf('Notification transport failures: %d', $failed));
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function address(string $key): Address
    {
        $value = (string) ($this->config[$key] ?? '');
        if (strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid sender.');
        }
        return new Address($value);
    }

    /** @psalm-suppress InternalMethod Symfony SMTP exposes the socket timeout through SocketStream. */
    private function transport(): EsmtpTransport
    {
        $host     = (string) ($this->config['host'] ?? '');
        $port     = (int) ($this->config['port'] ?? 587);
        $implicit = ($this->config['implicit_tls'] ?? false) === true;
        if (preg_match('/\A[a-z0-9][a-z0-9.-]{0,252}\z/iD', $host) !== 1 || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Invalid SMTP endpoint.');
        }

        $transport = new EsmtpTransport($host, $port, $implicit ? true : null);
        $transport->setRequireTls(true);
        $transport->setUsername((string) ($this->config['username'] ?? ''));
        $transport->setPassword((string) ($this->config['password'] ?? ''));
        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(5.0);
        }
        return $transport;
    }
}
