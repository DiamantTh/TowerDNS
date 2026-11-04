<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

http_response_code(403);
?><!DOCTYPE html>
<html lang="<?= e(str_replace('_', '-', (string) ($GLOBALS['_installer_locale'] ?? 'en-GB'))) ?>"
      data-theme="cerberus">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?= e(t('locked.title')) ?> — TowerDNS</title>
    <script src="/assets/<?= htmlspecialchars($GLOBALS['_installer_assets']['theme'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></script>
    <?php foreach ($GLOBALS['_installer_assets']['css'] as $assetCss): ?>
    <link rel="stylesheet" href="/assets/<?= htmlspecialchars($assetCss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <?php endforeach; ?>
    <style>
        :root { color-scheme: light; }
        body { background: #f5f5f5; }
    </style>
</head>
<body>
<section class="section">
    <div class="container" style="max-width:640px">
        <div class="notification is-danger">
            <strong>🔒 <?= e(t('locked.heading')) ?></strong><br>
            <?= e(t('locked.body')) ?><br><br>
            <strong><?= e(t('locked.recommendation')) ?></strong><br>
            <pre style="background:#fff;padding:.5rem;border-radius:4px;margin-top:.5rem"><code>rm -rf install/</code></pre>
        </div>
        <a href="/" class="button is-primary">→ <?= e(t('layout.to_app')) ?></a>
    </div>
</section>
</body>
</html>
