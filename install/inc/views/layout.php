<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * TowerDNS Installer — Layout-Wrapper
 *
 * Erwartet folgende Variablen aus dem aufrufenden Kontext:
 *   string   $pageTitle    – <title>-Inhalt (optional)
 *   string   $_step_content – gerenderter Schrittinhalt (ob_get_clean())
 *   bool     $showProgress  – Fortschrittsleiste anzeigen (default true)
 *   int      $displayStep   – aktueller Schritt 1–3
 *   string[] $stepLabels    – Schritt-Bezeichnungen
 *   string[] $errors        – Fehlermeldungen
 */

$locale = str_replace('_', '-', (string) ($GLOBALS['_installer_locale'] ?? 'en-GB'));
$showProgress ??= true;
$errors       ??= [];
$stepLabels   ??= [];
$displayStep  ??= 1;
?><!DOCTYPE html>
<html lang="<?= e($locale) ?>" data-theme="cerberus">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title><?= e($pageTitle ?? 'TowerDNS — ' . t('layout.title')) ?></title>
    <script src="/assets/theme-init.bundle.js"></script>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" type="image/svg+xml" href="../../assets/img/favicon.svg">
    <style>
        :root { --primary: #1565c0; --primary-dark: #0d47a1; color-scheme: light; }
        body  { background: #f5f5f5; }
        .wizard-card { max-width: 840px; margin: 2rem auto; }
        .progress-bar { display: flex; gap: .5rem; margin-bottom: 2rem; }
        .progress-bar .pb-step {
            flex: 1; text-align: center; padding: .45rem .3rem;
            border-radius: 8px; font-size: .8rem; font-weight: 700;
            background: #e2e8f0; color: #64748b;
        }
        .progress-bar .pb-step.done   { background: #388e3c; color: #fff; }
        .progress-bar .pb-step.active { background: #1565c0; color: #fff; }
        .section-divider { border: none; border-top: 2px solid #e2e8f0; margin: 1.5rem 0; }
        .section-title   { font-size: 1rem; font-weight: 700; color: #1565c0; margin-bottom: .75rem; }
        code { background: #f1f5f9; padding: .1em .3em; border-radius: 4px; font-size: .875em; }
        pre  { background: #f8fafc; border-radius: 6px; padding: .75rem 1rem; font-size: .85rem; }
        .warn-left { border-left: 4px solid #ff9800; }
        .lang-switcher { display: flex; flex-wrap: wrap; gap: .35rem; justify-content: flex-end; margin-bottom: .75rem; }
        .lang-switcher a {
            font-size: .72rem; padding: .15rem .45rem; border-radius: 4px;
            border: 1px solid #cbd5e1; color: #475569; text-decoration: none;
        }
        .lang-switcher a.active, .lang-switcher a:hover {
            background: #1565c0; border-color: #1565c0; color: #fff;
        }
    </style>
</head>
<body>
<div class="wizard-card card">
    <header class="card-header" style="background:linear-gradient(135deg,#1565c0,#0d47a1);border-radius:9px 9px 0 0">
        <p class="card-header-title" style="color:#fff;font-size:1.1rem">
            🗼 TowerDNS — <?= e(t('layout.title')) ?>
        </p>
    </header>
    <div class="card-content">

        <div class="lang-switcher" aria-label="<?= e(t('layout.lang_switch')) ?>">
            <?php foreach (INSTALLER_LANGS as $code => $native):
                $active = (str_replace('_', '-', $locale) === $code) ? ' active' : '';
                $url    = 'index.php?lang=' . urlencode($code);
                ?>
            <a href="<?= e($url) ?>" class="<?= $active ?>" lang="<?= e($code) ?>"
               hreflang="<?= e($code) ?>"><?= e($native) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ($showProgress && !empty($stepLabels)): ?>
        <div class="progress-bar" role="progressbar"
             aria-valuenow="<?= $displayStep ?>" aria-valuemin="1" aria-valuemax="3">
            <?php foreach ($stepLabels as $i => $lbl):
                $n    = $i + 1;
                $cls  = $n < $displayStep ? 'done' : ($n === $displayStep ? 'active' : '');
                $icon = $n < $displayStep ? '✓ ' : '';
                ?>
            <div class="pb-step <?= e($cls) ?>"><?= $icon . e($lbl) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="notification is-danger is-light mb-4" role="alert">
            <ul>
                <?php foreach ($errors as $errMsg): ?>
                <li><?= e((string) $errMsg) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?= $_step_content ?? '' ?>

    </div>
</div>
<script type="module" src="/assets/app.bundle.js"></script>
</body>
</html>
