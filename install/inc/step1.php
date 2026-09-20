<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * TowerDNS Installer — Schritt 1: System-Check
 */

function processStep1(): void
{
    verifyCsrf();

    $reqs        = getRequirements();
    $allRequired = true;
    foreach ($reqs as $req) {
        if ($req['required'] && !$req['ok']) {
            $allRequired = false;
            break;
        }
    }

    if ($allRequired) {
        $_SESSION['install_step'] = 2;
    }

    header('Location: ' . INSTALLER_ENTRY);
    exit;
}

// ── View ───────────────────────────────────────────────────────────────────
/** @param string[] $errors */
function renderStep1(array $errors): void
{
    global $displayStep, $pageTitle, $showProgress, $stepLabels;

    $reqs        = getRequirements();
    $allRequired = true;
    foreach ($reqs as $req) {
        if ($req['required'] && !$req['ok']) {
            $allRequired = false;
            break;
        }
    }

    $vendorMissing = !VENDOR_OK;

    ob_start();
    ?>
<h2 class="subtitle is-5 mb-4"><?= e(t('step1.subheading')) ?></h2>

<?php if ($vendorMissing): ?>
<div class="notification is-warning is-light mb-4">
    <strong>⚠ <?= e(t('step1.vendor_heading')) ?></strong><br>
    <?= e(t('step1.vendor_body')) ?>
    <pre class="mt-2" style="background:#fff3cd;padding:.5rem;border-radius:4px"><code>composer install --no-dev</code></pre>
    <?= e(t('step1.vendor_reload')) ?>
</div>
<?php endif; ?>

<table class="table is-fullwidth is-striped is-hoverable">
    <thead>
        <tr>
            <th><?= e(t('step1.col_check')) ?></th>
            <th><?= e(t('step1.col_status')) ?></th>
            <th><?= e(t('step1.col_detail')) ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($reqs as $req): ?>
        <tr>
            <td>
                <?= e($req['label']) ?>
                <?php if ($req['required']): ?>
                    <span class="tag is-danger is-light ml-1" style="font-size:.7rem"><?= e(t('step1.required')) ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($req['ok']): ?>
                    <span class="tag is-success is-light">✔ <?= e(t('step1.ok')) ?></span>
                <?php elseif ($req['required']): ?>
                    <span class="tag is-danger">✘ <?= e(t('step1.missing')) ?></span>
                <?php else: ?>
                    <span class="tag is-warning is-light"><?= e(t('step1.notice')) ?></span>
                <?php endif; ?>
            </td>
            <td class="is-size-7"><?= e($req['detail']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if (!$allRequired): ?>
<div class="notification is-danger is-light">
    ✘ <?= e(t('step1.reqs_not_met')) ?>
</div>
<?php endif; ?>

<form method="post" action="<?= e(INSTALLER_ENTRY) ?>">
    <input type="hidden" name="csrf_token" value="<?= e(CSRF_TOKEN) ?>">
    <input type="hidden" name="action" value="step1">
    <div class="field is-grouped is-grouped-right">
        <div class="control">
            <button type="submit" class="button is-primary"
                <?php if (!$allRequired): ?>
                    disabled title="<?= e(t('step1.btn_disabled_title')) ?>"
                <?php endif; ?>
            >
                <?= e(t('step1.btn_next')) ?> →
            </button>
        </div>
    </div>
</form>
<?php
    $_step_content = ob_get_clean();

    require INSTALL_DIR . '/inc/views/layout.php';
}
