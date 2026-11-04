<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require dirname(__DIR__) . '/src/Infrastructure/Ui/AssetManifestException.php';
require dirname(__DIR__) . '/src/Infrastructure/Ui/AssetManifest.php';
(new TowerDNS\Infrastructure\Ui\AssetManifest(dirname(__DIR__)))->activate();
echo "Frontend release verified and activated.\n";
