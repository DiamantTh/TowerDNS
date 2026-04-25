<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * TowerDNS Installer — Schritt 3: Bestätigung + Ausführung
 * Erstellt Datenbank-Schema, Admin-Benutzer, RBAC-Rollen
 * und schreibt alle Konfigurationsdateien.
 */

/** @return string[] */
function processStep3(): array
{
    $db        = $_SESSION['install_db']        ?? null;
    $admin     = $_SESSION['install_admin']     ?? null;
    $app       = $_SESSION['install_app']       ?? null;
    $providers = $_SESSION['install_providers'] ?? null;

    if (!$db || !$admin || !$app || !$providers) {
        $_SESSION['install_step'] = 1;
        return [t('step3.session_lost')];
    }

    try {
        // ── Doctrine DBAL-Verbindung ──────────────────────────────────────
        $params = match ($db['driver']) {
            'pdo_sqlite' => [
                'driver' => 'pdo_sqlite',
                'path'   => $db['path'],
            ],
            'pdo_pgsql' => [
                'driver'   => 'pdo_pgsql',
                'host'     => $db['host'],
                'port'     => (int) $db['port'],
                'dbname'   => $db['name'],
                'user'     => $db['user'],
                'password' => $db['pass'] ?? '',
            ],
            default => [
                'driver'   => 'pdo_mysql',
                'host'     => $db['host'],
                'port'     => (int) $db['port'],
                'dbname'   => $db['name'],
                'user'     => $db['user'],
                'password' => $db['pass'] ?? '',
                'charset'  => 'utf8mb4',
            ],
        };

        $conn          = \Doctrine\DBAL\DriverManager::getConnection($params);
        $schemaManager = new \TowerDNS\Infrastructure\Persistence\SchemaManager($conn);

        // ── Schema + System-Rollen anlegen ────────────────────────────────
        $schemaManager->createTablesIfNotExist();
        $schemaManager->seedSystemRoles();

        // ── Admin-Benutzer anlegen ────────────────────────────────────────
        $now     = (new \DateTime())->format('Y-m-d H:i:s');
        $hash    = password_hash(
            $admin['password'],
            PASSWORD_ARGON2ID,
            ['memory_cost' => 131072, 'time_cost' => 4, 'threads' => 4]
        );
        $adminId = sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(chr((ord(random_bytes(1)[0]) & 0x0f) | 0x40)) . bin2hex(random_bytes(1)),
            bin2hex(chr((ord(random_bytes(1)[0]) & 0x3f) | 0x80)) . bin2hex(random_bytes(1)),
            bin2hex(random_bytes(6))
        );
        $schemaManager->seedFirstUser($adminId, $admin['email'], $hash, $admin['username']);

        // ── configs/-Verzeichnis sicherstellen ─────────────────────────────────
        $cfgDir = PROJECT_ROOT . '/configs';
        if (!is_dir($cfgDir)) {
            mkdir($cfgDir, 0750, true);
        }

        // ── configs/config.local.toml ──────────────────────────────────
        $encKey          = base64_encode(random_bytes(32));
        $escapedEncKey   = addcslashes($encKey,        '"\\');
        $escapedDomain   = addcslashes($app['domain'], '"\\');
        $escapedAppName  = addcslashes($app['name'],   '"\\');
        $escapedAppTheme = addcslashes($app['theme'],  '"\\');
        $forceHttps      = $app['https'] ? 'true' : 'false';

        $localToml = <<<TOML
# TowerDNS — Lokale Konfiguration (auto-generiert am {$now})
# NIEMALS ins Git einpflegen!

[security]
encryption_key = "{$escapedEncKey}"

[app]
domain      = "{$escapedDomain}"
force_https = {$forceHttps}
debug       = false

[application]
name = "{$escapedAppName}"

[theme]
name = "{$escapedAppTheme}"
TOML;

        $localTomlFile = $cfgDir . '/config.local.toml';
        if (file_exists($localTomlFile)) {
            copy($localTomlFile, $localTomlFile . '.bak.' . date('Y-m-d-H-i-s'));
        }
        file_put_contents($localTomlFile, $localToml);
        chmod($localTomlFile, 0600);

        // ── configs/database.toml ──────────────────────────────────────────────
        if ($db['driver'] === 'pdo_sqlite') {
            $escapedPath = addcslashes($db['path'], '"\\');
            $dbToml = <<<TOML
# TowerDNS — Datenbankkonfiguration (auto-generiert am {$now})

[database]
driver = "pdo_sqlite"

[database.sqlite]
path = "{$escapedPath}"
TOML;
        } else {
            $dbDriver  = addcslashes($db['driver'],     '"\\');
            $dbHost    = addcslashes($db['host'],       '"\\');
            $dbPort    = (int) $db['port'];
            $dbName    = addcslashes($db['name'],       '"\\');
            $dbUser    = addcslashes($db['user'],       '"\\');
            $dbPass    = addcslashes($db['pass'] ?? '', '"\\');

            $dbToml = <<<TOML
# TowerDNS — Datenbankkonfiguration (auto-generiert am {$now})
# Passwort besser via DB_PASSWORD-Umgebungsvariable statt in dieser Datei.

[database]
driver   = "{$dbDriver}"
host     = "{$dbHost}"
port     = {$dbPort}
name     = "{$dbName}"
user     = "{$dbUser}"
password = "{$dbPass}"
TOML;
            if ($db['driver'] === 'pdo_mysql') {
                $dbToml .= "\ncharset   = \"utf8mb4\"";
                $dbToml .= "\ncollation = \"utf8mb4_unicode_ci\"";
            }
        }

        $dbTomlFile = $cfgDir . '/database.toml';
        if (file_exists($dbTomlFile)) {
            copy($dbTomlFile, $dbTomlFile . '.bak.' . date('Y-m-d-H-i-s'));
        }
        file_put_contents($dbTomlFile, $dbToml);
        chmod($dbTomlFile, 0600);

        // ── configs/providers.toml ────────────────────────────────────────
        $providersToml = "# TowerDNS — Provider-Konfiguration (auto-generiert am {$now})\n";
        $providersToml .= "# NIEMALS ins Git einpflegen!\n\n";

        if (isset($providers['desec'])) {
            $t = addcslashes($providers['desec']['token'], '"\\');
            $providersToml .= "[providers.desec]\ntoken = \"{$t}\"\n\n";
        }

        if (isset($providers['powerdns'])) {
            $u = addcslashes($providers['powerdns']['base_url'],  '"\\');
            $k = addcslashes($providers['powerdns']['api_key'],   '"\\');
            $s = addcslashes($providers['powerdns']['server_id'], '"\\');
            $providersToml .= "[providers.powerdns]\nbase_url  = \"{$u}\"\napi_key   = \"{$k}\"\nserver_id = \"{$s}\"\n\n";
        }

        if (isset($providers['cloudflare'])) {
            $ct = addcslashes($providers['cloudflare']['api_token'], '"\\');
            $providersToml .= "[providers.cloudflare]\napi_token = \"{$ct}\"\n\n";
        }

        if (isset($providers['inwx'])) {
            $iu = addcslashes($providers['inwx']['username'], '"\\');
            $ip = addcslashes($providers['inwx']['password'], '"\\');
            $providersToml .= "[providers.inwx]\nusername = \"{$iu}\"\npassword = \"{$ip}\"\n\n";
        }

        $providersTomlFile = $cfgDir . '/providers.toml';
        if (file_exists($providersTomlFile)) {
            copy($providersTomlFile, $providersTomlFile . '.bak.' . date('Y-m-d-H-i-s'));
        }
        file_put_contents($providersTomlFile, rtrim($providersToml) . "\n");
        chmod($providersTomlFile, 0600);

        // ── Lock-Datei ────────────────────────────────────────────────────
        file_put_contents(LOCK_FILE, $now . "\n");

        // ── Session abschliessen ──────────────────────────────────────────
        $_SESSION['install_result'] = [
            'admin_user' => $admin['email'],
            'admin_pass' => $admin['password'],
        ];
        unset(
            $_SESSION['install_db'],
            $_SESSION['install_admin'],
            $_SESSION['install_app'],
            $_SESSION['install_providers'],
            $_SESSION['install_step']
        );

    } catch (\Throwable $ex) {
        return [sprintf(t('step3.install_failed'), e($ex->getMessage()))];
    }

    return [];
}

// ── View (Bestätigung) ─────────────────────────────────────────────────────
$db        = $_SESSION['install_db']        ?? [];
$admin     = $_SESSION['install_admin']     ?? [];
$app       = $_SESSION['install_app']       ?? [];
$providers = $_SESSION['install_providers'] ?? [];

ob_start();
?>
<h2 class="title is-5"><?= e(t('step3.heading')) ?></h2>
<p class="mb-4 has-text-grey is-size-7"><?= e(t('step3.subheading')) ?></p>

<div class="notification is-warning is-light mb-4">
    ⚠️ <?= e(t('step3.warning')) ?>
</div>

<?php /* Datenbank */ ?>
<p class="section-title">🗄️ <?= e(t('step2.db_section')) ?></p>
<table class="table is-fullwidth is-bordered is-size-7 mb-4">
    <tbody>
    <tr><td><strong><?= e(t('step2.db_driver')) ?></strong></td><td><?= e($db['driver'] ?? '') ?></td></tr>
    <?php if (($db['driver'] ?? '') === 'pdo_sqlite'): ?>
    <tr><td><strong><?= e(t('step2.sqlite_path')) ?></strong></td><td><?= e($db['path'] ?? '') ?></td></tr>
    <?php else: ?>
    <tr><td><strong><?= e(t('step2.hostname')) ?></strong></td><td><?= e($db['host'] ?? '') ?></td></tr>
    <tr><td><strong><?= e(t('step2.port')) ?></strong></td><td><?= e((string) ($db['port'] ?? '')) ?></td></tr>
    <tr><td><strong><?= e(t('step2.dbname')) ?></strong></td><td><?= e($db['name'] ?? '') ?></td></tr>
    <tr><td><strong><?= e(t('step2.dbuser')) ?></strong></td><td><?= e($db['user'] ?? '') ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php /* Admin */ ?>
<p class="section-title">👤 <?= e(t('step2.admin_section')) ?></p>
<table class="table is-fullwidth is-bordered is-size-7 mb-4">
    <tbody>
    <tr><td><strong><?= e(t('step2.admin_username')) ?></strong></td><td><?= e($admin['username'] ?? '') ?></td></tr>
    <tr><td><strong><?= e(t('step2.admin_email')) ?></strong></td><td><?= e($admin['email'] ?? '') ?></td></tr>
    </tbody>
</table>

<?php /* App */ ?>
<p class="section-title">⚙️ <?= e(t('step2.app_section')) ?></p>
<table class="table is-fullwidth is-bordered is-size-7 mb-4">
    <tbody>
    <tr><td><strong><?= e(t('step2.app_name')) ?></strong></td><td><?= e($app['name'] ?? '') ?></td></tr>
    <tr><td><strong><?= e(t('step2.app_domain')) ?></strong></td><td><?= e($app['domain'] ?? '') ?></td></tr>
    <tr><td><strong><?= e(t('step2.app_theme')) ?></strong></td><td><?= e($app['theme'] ?? '') ?></td></tr>
    <tr><td><strong>HTTPS</strong></td><td><?= ($app['https'] ?? false) ? '✓' : '✗' ?></td></tr>
    </tbody>
</table>

<?php /* Provider */ ?>
<p class="section-title">🌐 <?= e(t('step2.provider_section')) ?></p>
<table class="table is-fullwidth is-bordered is-size-7 mb-4">
    <tbody>
    <?php if (!empty($providers['desec'])): ?>
    <tr><td><strong>deSEC</strong></td><td><?= e(t('step3.provider_configured')) ?></td></tr>
    <?php endif; ?>
    <?php if (!empty($providers['powerdns'])): ?>
    <tr><td><strong>PowerDNS</strong></td>
        <td><?= e($providers['powerdns']['base_url'] ?? '') ?> / <?= e($providers['powerdns']['server_id'] ?? '') ?></td></tr>
    <?php endif; ?>
    <?php if (!empty($providers['cloudflare'])): ?>
    <tr><td><strong>Cloudflare</strong></td><td><?= e(t('step3.provider_configured')) ?></td></tr>
    <?php endif; ?>
    <?php if (!empty($providers['inwx'])): ?>
    <tr><td><strong>INWX</strong></td><td><?= e($providers['inwx']['username'] ?? '') ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>

<div class="buttons mt-5">
    <form method="post" action="index.php" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= e(CSRF_TOKEN) ?>">
        <input type="hidden" name="action" value="install">
        <button type="submit" class="button is-danger is-medium"
                onclick="return confirm('<?= e(t('step3.confirm')) ?>')">
            🚀 <?= e(t('step3.btn_install')) ?>
        </button>
    </form>
    <a href="index.php?back=1" class="button is-light is-medium">← <?= e(t('nav.back')) ?></a>
</div>
<?php
$_step_content = ob_get_clean();

require INSTALL_DIR . '/inc/views/layout.php';
