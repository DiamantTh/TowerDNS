<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Backup;

/** Prevents a server database left by a failed native import from being reused. */
final readonly class RestoreTargetGuard
{
    public function __construct(private string $stateDirectory) {}

    public function assertClean(string $identity): void
    {
        if (is_file($this->marker($identity))) {
            throw new BackupException('Restore target was marked tainted by a previous failed restore. Keep it offline and use a new isolated database.');
        }
    }

    public function markTainted(string $identity): void
    {
        if (!is_dir($this->stateDirectory) && !@mkdir($this->stateDirectory, 0o700, true) && !is_dir($this->stateDirectory)) {
            throw new BackupException('Restore failed and its target could not be marked tainted. Keep it offline and do not retry into this database.');
        }
        if (is_link($this->stateDirectory) || !chmod($this->stateDirectory, 0o700)) {
            throw new BackupException('Restore failed and the taint state directory is unsafe. Keep the target offline and do not retry into this database.');
        }
        $path = $this->marker($identity);
        $data = json_encode(['format' => 'towerdns/failed-restore', 'marked_at' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR) . "\n";
        $temp = $path . '.' . bin2hex(random_bytes(8)) . '.pending';
        if (file_put_contents($temp, $data, LOCK_EX) !== strlen($data) || !chmod($temp, 0o600) || !rename($temp, $path)) {
            @unlink($temp);
            throw new BackupException('Restore failed and its target could not be marked tainted. Keep it offline and do not retry into this database.');
        }
    }

    private function marker(string $identity): string
    {
        return rtrim($this->stateDirectory, '/') . '/' . hash('sha256', $identity) . '.json';
    }
}
