<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Backup;

/** Operator-facing errors with no driver output, credentials or database contents. */
final class BackupException extends \RuntimeException {}
