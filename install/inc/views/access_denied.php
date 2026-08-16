<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

http_response_code(403);
$wrongToken = isset($_POST['install_token']) && $_POST['install_token'] !== '';
?><!DOCTYPE html>
<html lang="<?= e(str_replace('_', '-', (string) ($GLOBALS['_installer_locale'] ?? 'en-GB'))) ?>"
      data-theme="cerberus">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?= e(t('access.title')) ?> — TowerDNS</title>
    <script src="/assets/theme-init.bundle.js"></script>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <style>
        :root { color-scheme: light; }
        body { background: linear-gradient(135deg,#e3f0ff 0%,#e8f5e9 100%); min-height: 100vh; }
        .token-card { max-width: 640px; margin: 3rem auto; }
        pre code { font-size: .875rem; word-break: break-all; white-space: pre-wrap; }
    </style>
</head>
<body>
<section class="section">
    <div class="token-card">
        <div class="has-text-centered mb-5">
            <img src="/favicon.svg" alt="TowerDNS" width="64" height="64">
            <h1 class="title is-4 mt-2" style="color:#1565c0">TowerDNS — <?= e(t('layout.title')) ?></h1>
        </div>

        <?php if ($wrongToken): ?>
        <div class="notification is-danger is-light mb-4" role="alert">
            <strong>❌ <?= e(t('access.invalid_token')) ?></strong>
        </div>
        <?php endif; ?>

        <div class="notification" style="background:#fff8e1;border-left:4px solid #f9a825;color:#4e3900">
            <strong>🔒 <?= e(t('access.protected')) ?></strong><br>
            <?= e(t('access.protected_hint')) ?>
        </div>

        <div class="box">
            <form method="post" action="index.php" autocomplete="off">
                <div class="field">
                    <label class="label" for="install_token"><?= e(t('access.token_label')) ?></label>
                    <div class="control">
                        <input class="input<?= $wrongToken ? ' is-danger' : '' ?>"
                               type="password"
                               id="install_token"
                               name="install_token"
                               placeholder="<?= e(t('access.token_placeholder')) ?>"
                               aria-label="<?= e(t('access.token_label')) ?>"
                               required>
                    </div>
                </div>
                <button type="submit" class="button is-primary is-fullwidth mt-3"
                        style="background:#1565c0;border-color:#0d47a1">
                    🔓 <?= e(t('access.unlock')) ?>
                </button>
            </form>
        </div>

        <div class="notification is-info is-light is-size-7">
            <?= e(t('access.token_retrieve')) ?>
            <pre class="mt-2"><code>cat <?= e(TOKEN_FILE) ?></code></pre>
        </div>
    </div>
</section>
<script type="module" src="/assets/app.bundle.js"></script>
</body>
</html>
