<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

/**
 * Web-Installer-Einstiegspunkt.
 *
 * Leitet die Anfrage an install/index.php weiter.
 * Der Installer prüft selbst, ob er bereits gesperrt ist (Marker außerhalb
 * von install/ oder der Legacy-Lockdatei).
 */
require dirname(__DIR__) . '/install/index.php';
