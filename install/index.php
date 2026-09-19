<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * TowerDNS Installer — Einstiegspunkt
 *
 * Routing-Reihenfolge:
 *   1. bootstrap.php laden (Session, CSRF, Lock/Token-Checks)
 *   2. Wenn Installationsmarker oder LOCK_FILE existiert → locked-View anzeigen
 *   3. Wenn Installer-Token nicht verifiziert → access_denied-View anzeigen
 *   4. vendor/autoload.php laden, helpers + i18n initialisieren
 *   5. ?back=1-Navigation verarbeiten
 *   6. POST-Actions verarbeiten: step1, step2, step3 (install), cleanup
 *   7. Aktuellen Schritt rendern
 */

require_once __DIR__ . '/inc/bootstrap.php';

// ── Bereits installiert? ───────────────────────────────────────────────────
if (installationIsLocked()) {
    // i18n so früh wie möglich initialisieren (best-effort, kein autoload nötig)
    if (VENDOR_OK) {
        require_once PROJECT_ROOT . '/vendor/autoload.php';
        require_once INSTALL_DIR . '/inc/helpers.php';
        require_once INSTALL_DIR . '/inc/i18n.php';
        initTranslator();
    }
    require INSTALL_DIR . '/inc/views/locked.php';
    exit;
}

// ── Token-Schutz ───────────────────────────────────────────────────────────
if (!checkInstallerToken()) {
    if (VENDOR_OK) {
        require_once PROJECT_ROOT . '/vendor/autoload.php';
        require_once INSTALL_DIR . '/inc/helpers.php';
        require_once INSTALL_DIR . '/inc/i18n.php';
        initTranslator();
    }
    require INSTALL_DIR . '/inc/views/access_denied.php';
    exit;
}

// ── Autoloader + Helpers ───────────────────────────────────────────────────
if (!VENDOR_OK) {
    // Kein Autoloader verfügbar — helpers.php benötigt keinen vendor/, step1 direkt rendern.
    // So erscheint die vendor/-Zeile in der Anforderungstabelle als "FEHLT".
    require_once INSTALL_DIR . '/inc/helpers.php';

    // Minimales t(): lädt en-GB.php direkt ohne Laminas-Abhängigkeit
    function t(string $key, array $params = []): string
    {
        static $strings;
        if ($strings === null) {
            $file    = INSTALL_DIR . '/lang/en-GB.php';
            $strings = is_file($file) ? (array) require $file : [];
        }
        $val = (string) ($strings[$key] ?? $key);
        return $params !== [] ? (string) vsprintf($val, $params) : $val;
    }

    if (!defined('INSTALLER_LANGS')) {
        define('INSTALLER_LANGS', ['en-GB' => 'English']);
    }
    $GLOBALS['_installer_locale'] = 'en-GB';

    // Schritt 1 anzeigen (Anforderungstabelle — keine vendor/-Abhängigkeit)
    $_SESSION['install_step'] = 1;
    $currentStep  = 1;
    $displayStep  = 1;
    $showProgress = true;
    $stepLabels   = [t('steps.s1'), t('steps.s2'), t('steps.s3')];
    $pageTitle    = 'TowerDNS — ' . t('layout.title');
    $errors       = [];
    require INSTALL_DIR . '/inc/step1.php';
    exit;
}

require_once PROJECT_ROOT . '/vendor/autoload.php';
require_once INSTALL_DIR . '/inc/helpers.php';
require_once INSTALL_DIR . '/inc/i18n.php';

$locale = initTranslator();

// ── Schritt-Initialisierung ────────────────────────────────────────────────
if (!isset($_SESSION['install_step'])) {
    $_SESSION['install_step'] = 1;
}

// ── ?back=1-Navigation ─────────────────────────────────────────────────────
if (isset($_GET['back']) && $_GET['back'] === '1') {
    $currentStep = (int) ($_SESSION['install_step'] ?? 1);
    if ($currentStep > 1) {
        $_SESSION['install_step'] = $currentStep - 1;
    }
    header('Location: ' . INSTALLER_ENTRY);
    exit;
}

// ── Cleanup nach Erfolg ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'cleanup'
) {
    verifyCsrf();
    rmDirRecursive(INSTALL_DIR);
    header('Location: /');
    exit;
}

// ── Step-1-Action ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'step1'
) {
    require_once INSTALL_DIR . '/inc/step1.php';
    processStep1(); // redirects on success
    exit;           // processStep1 calls exit internally
}

// ── Step-2-Action ──────────────────────────────────────────────────────────
$step2Errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'step2'
) {
    require_once INSTALL_DIR . '/inc/step2.php';
    $step2Errors = processStep2();
    if (empty($step2Errors)) {
        header('Location: ' . INSTALLER_ENTRY);
        exit;
    }
    // Bei Fehlern Schritt 2 erneut anzeigen
    $_SESSION['install_step'] = 2;
}

// ── Install-Action (Schritt 3) ─────────────────────────────────────────────
$step3Errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'install'
) {
    verifyCsrf();
    require_once INSTALL_DIR . '/inc/step3.php';
    $step3Errors = processStep3();
    if (empty($step3Errors) && isset($_SESSION['install_result'])) {
        require INSTALL_DIR . '/inc/views/success.php';
        exit;
    }
    // Bei Fehlern Schritt 3 erneut anzeigen
    $_SESSION['install_step'] = 3;
}

// ── Schritt rendern ────────────────────────────────────────────────────────
$currentStep = (int) ($_SESSION['install_step'] ?? 1);

$stepLabels  = [
    t('steps.s1'),
    t('steps.s2'),
    t('steps.s3'),
];

$pageTitle   = 'TowerDNS — ' . t('layout.title');
$showProgress = true;
$displayStep  = $currentStep;
$errors       = [];

switch ($currentStep) {
    case 3:
        $errors = $step3Errors;
        require_once INSTALL_DIR . '/inc/step3.php';
        // step3.php setzt $_step_content und ruft layout.php auf
        break;

    case 2:
        $errors = $step2Errors;
        require_once INSTALL_DIR . '/inc/step2.php';
        // step2.php setzt $_step_content und ruft layout.php auf
        break;

    default:
        require_once INSTALL_DIR . '/inc/step1.php';
        // step1.php setzt $_step_content und ruft layout.php auf
        break;
}
