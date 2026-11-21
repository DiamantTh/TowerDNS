<?php

declare(strict_types=1);

namespace TowerDNS\Application\Exception;

/** The client's If-Match revision was stale before a provider write began. */
final class RrsetPreconditionException extends RrsetConflictException {}
