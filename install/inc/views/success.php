<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * TowerDNS Installer — Erfolgs-View
 */

/** @var array{admin_user: string, admin_pass: string} $result */
$result = $_SESSION['install_result'] ?? [];
unset($_SESSION['install_result']);
?><!DOCTYPE html>
<html lang="<?= e(str_replace('_', '-', (string) ($GLOBALS['_installer_locale'] ?? 'en-GB'))) ?>"
      data-theme="cerberus">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?= e(t('success.heading')) ?> — TowerDNS</title>
    <script src="/assets/theme-init.bundle.js"></script>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <style>
        :root { color-scheme: light; }
        body { background: #f5f5f5; }
        .result-card { max-width: 640px; margin: 3rem auto; }
        .warn-left { border-left: 4px solid #ff9800; }
    </style>
</head>
<body>
<div class="result-card card">
    <header class="card-header" style="background:linear-gradient(135deg,#1565c0,#0d47a1);border-radius:9px 9px 0 0">
        <p class="card-header-title" style="color:#fff">🗼 TowerDNS — <?= e(t('layout.title')) ?></p>
    </header>
    <div class="card-content">

        <div class="notification is-success" role="status">
            <strong>✅ <?= e(t('success.heading')) ?></strong>
        </div>

        <div class="box" style="border:2px solid #4caf50">
            <p class="mb-2">
                <strong><?= e(t('success.admin_user')) ?>:</strong>
                <code><?= e($result['admin_user'] ?? '') ?></code>
            </p>
            <p>
                <strong><?= e(t('success.admin_pass')) ?>:</strong>
                <code style="background:#e8f5e9;padding:.3em .6em;border-radius:4px">
                    <?= e($result['admin_pass'] ?? '') ?>
                </code>
            </p>
            <p class="mt-3 is-size-7 has-text-danger">⚠️ <?= e(t('success.pass_warning')) ?></p>
        </div>

        <div class="notification is-danger is-light mt-3 warn-left">
            <strong><?= e(t('success.security_hint')) ?>:</strong>
            <?= e(t('success.security_body')) ?>
        </div>

        <div class="buttons mt-4">
            <form method="post" action="index.php" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= e(CSRF_TOKEN) ?>">
                <input type="hidden" name="action" value="cleanup">
                <button type="submit" class="button is-danger"
                        onclick="return confirm('<?= e(t('success.confirm_delete')) ?>')">
                    🗑 <?= e(t('success.delete_installer')) ?>
                </button>
            </form>
            <a href="../../index.php" class="button is-primary">→ <?= e(t('layout.to_app')) ?></a>
        </div>

    </div>
</div>
<script type="module" src="/assets/app.bundle.js"></script>
</body>
</html>
