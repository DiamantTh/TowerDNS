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

    // install_providers is legitimately an empty array when the operator
    // configures zero DNS providers up front; only null (missing session
    // data) means the wizard state was actually lost.
    if (!$db || !$admin || !$app || $providers === null) {
        $_SESSION['install_step'] = 1;
        return [t('step3.session_lost')];
    }

    $lockWritten = false;

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

        $conn = Doctrine\DBAL\DriverManager::getConnection($params);

        // ── Admin-Benutzer-ID vorbereiten ─────────────────────────────────
        $now  = new DateTime()->format('Y-m-d H:i:s');
        $hash = password_hash(
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

        // ── Schema, System-Rollen, Admin-Benutzer, Standard-Account ───────
        // Über denselben zentralen Bootstrapper wie der CLI-Installer, damit
        // ein abgebrochener/wiederholter Lauf nie einen mehrdeutigen
        // Zwischenzustand (z. B. Account ohne Owner) still übernimmt.
        $defaultSlug = substr(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $app['name'] ?? 'default') ?? 'default'), 0, 64);
        $defaultSlug = trim($defaultSlug, '-') ?: 'default';

        new TowerDNS\Infrastructure\Installation\FreshInstallBootstrapper($conn)->bootstrap(
            new TowerDNS\Infrastructure\Installation\FreshInstallBootstrapRequest(
                $adminId,
                $admin['email'],
                $hash,
                $admin['username'],
                $app['name'] ?? 'TowerDNS',
                $defaultSlug,
                $now,
            )
        );

        // ── Runtime-Verzeichnisse anlegen ─────────────────────────────────────
        foreach ([
            PROJECT_ROOT . '/configs'         => 0o750,
            PROJECT_ROOT . '/cache/ratelimit' => 0o750,
            PROJECT_ROOT . '/data'            => 0o750,
            PROJECT_ROOT . '/logs'            => 0o750,
        ] as $dir => $mode) {
            if (!is_dir($dir) && !@mkdir($dir, $mode, true)) {
                throw new RuntimeException(sprintf('Verzeichnis konnte nicht erstellt werden: %s', $dir));
            }
        }
        $cfgDir = PROJECT_ROOT . '/configs';
        $writer = new TowerDNS\Infrastructure\Configuration\AtomicConfigurationWriter();

        // ── configs/config.local.toml ──────────────────────────────────
        $encKey          = TowerDNS\Application\Services\CredentialService::generateKey();
        $escapedEncKey   = addcslashes($encKey, '"\\');
        $escapedDomain   = addcslashes($app['domain'], '"\\');
        $escapedAppName  = addcslashes($app['name'], '"\\');
        $escapedAppTheme = addcslashes($app['theme'], '"\\');
        $forceHttps      = $app['https'] ? 'true' : 'false';
        $baseUrl         = ($app['domain'] ?? '') !== ''
            ? (($app['https'] ? 'https' : 'http') . '://' . $app['domain'])
            : '';
        $escapedBaseUrl = addcslashes($baseUrl, '"\\');

        $localToml = <<<TOML
            # TowerDNS — Lokale Konfiguration (auto-generiert am {$now})
            # NIEMALS ins Git einpflegen!
            #
            # Diese Datei enthält ausschliesslich Bootstrap-Werte
            # (DB-Connection, Crypto-Schluessel, Hostname). Alle
            # Runtime-Einstellungen (Passwort-Policy, HIBP, Theme, Mailer
            # …) liegen in der DB-Tabelle `system_settings` und werden
            # ueber die Web-UI gepflegt.

            [security]
            encryption_key = "{$escapedEncKey}"

            [app]
            domain      = "{$escapedDomain}"
            base_url   = "{$escapedBaseUrl}"
            force_https = {$forceHttps}
            debug       = false
            # Steht TowerDNS hinter einem Reverse Proxy (nginx, traefik, ...),
            # hier dessen IP(s)/CIDR(s) eintragen, damit der X-Forwarded-For-
            # Header fuer Rate-Limiting und Audit-Log vertraut wird. Leer
            # (Standard) heisst: nur die direkte Verbindung (REMOTE_ADDR)
            # wird vertraut.
            trusted_proxies = []

            [session]
            # Keep this true for every HTTPS deployment, including TLS-terminating proxies.
            cookie_secure = {$forceHttps}

            [application]
            name = "{$escapedAppName}"

            [theme]
            name = "{$escapedAppTheme}"
            TOML;

        $localTomlFile = $cfgDir . '/config.local.toml';
        if (file_exists($localTomlFile)) {
            copy($localTomlFile, $localTomlFile . '.bak.' . date('Y-m-d-H-i-s'));
        }
        $writer->write($localTomlFile, $localToml);

        // ── configs/database.toml ──────────────────────────────────────────────
        if ($db['driver'] === 'pdo_sqlite') {
            $escapedPath = addcslashes($db['path'], '"\\');
            $dbToml      = <<<TOML
                # TowerDNS — Datenbankkonfiguration (auto-generiert am {$now})

                [database]
                driver = "pdo_sqlite"

                [database.sqlite]
                path = "{$escapedPath}"
                TOML;
        } else {
            $dbDriver = addcslashes($db['driver'], '"\\');
            $dbHost   = addcslashes($db['host'], '"\\');
            $dbPort   = (int) $db['port'];
            $dbName   = addcslashes($db['name'], '"\\');
            $dbUser   = addcslashes($db['user'], '"\\');
            $dbPass   = addcslashes($db['pass'] ?? '', '"\\');

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
        $writer->write($dbTomlFile, $dbToml);

        // ── configs/providers.toml ────────────────────────────────────────
        $providersToml = "# TowerDNS — Provider-Konfiguration (auto-generiert am {$now})\n";
        $providersToml .= "# NIEMALS ins Git einpflegen!\n\n";

        if (isset($providers['desec'])) {
            $t = addcslashes($providers['desec']['token'], '"\\');
            $providersToml .= "[providers.desec]\ntoken = \"{$t}\"\n\n";
        }

        if (isset($providers['powerdns'])) {
            $u = addcslashes($providers['powerdns']['base_url'], '"\\');
            $k = addcslashes($providers['powerdns']['api_key'], '"\\');
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
        $writer->write($providersTomlFile, rtrim($providersToml) . "\n");

        // ── Lock-Datei ────────────────────────────────────────────────────
        $writer->write(LOCK_FILE, $now . "\n");
        $lockWritten = true;
        // This marker is deliberately outside install/, because successful
        // cleanup removes the installer directory entirely.
        $writer->write(INSTALLATION_MARKER, $now . "\n");

        // ── Session abschliessen ──────────────────────────────────────────
        $_SESSION['install_result'] = [
            'admin_user' => $admin['email'],
        ];
        unset(
            $_SESSION['install_db'],
            $_SESSION['install_admin'],
            $_SESSION['install_app'],
            $_SESSION['install_providers'],
            $_SESSION['install_step']
        );
    } catch (Throwable $ex) {
        // A failed marker write must not leave the legacy lock behind: the
        // installer must remain retryable after an interrupted first run.
        if ($lockWritten && !is_file(INSTALLATION_MARKER)) {
            @unlink(LOCK_FILE);
        }
        error_log('TowerDNS installer failed: ' . $ex->getMessage());
        return [t('step3.install_failed')];
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
    <form method="post" action="<?= e(INSTALLER_ENTRY) ?>" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= e(CSRF_TOKEN) ?>">
        <input type="hidden" name="action" value="install">
        <button type="submit" class="button is-danger is-medium"
                onclick="return confirm('<?= e(t('step3.confirm')) ?>')">
            🚀 <?= e(t('step3.btn_install')) ?>
        </button>
    </form>
    <a href="<?= e(INSTALLER_ENTRY) ?>?back=1" class="button is-light is-medium">← <?= e(t('nav.back')) ?></a>
</div>
<?php
$_step_content = ob_get_clean();

require INSTALL_DIR . '/inc/views/layout.php';
