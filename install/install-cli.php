#!/usr/bin/env php
<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * TowerDNS — CLI-Installer
 *
 * Interaktive Installation ohne Web-Browser.
 * Benötigt: PHP 8.4+, Composer vendor/, pdo, pdo_mysql|pdo_pgsql|pdo_sqlite,
 *           openssl, sodium, intl
 *
 * Verwendung: php install/install-cli.php
 */

// ── PHP-Versionscheck ─────────────────────────────────────────────────────
if (PHP_MAJOR_VERSION < 8 || (PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION < 4)) {
    fwrite(STDERR, "TowerDNS requires PHP >= 8.4 (you have " . PHP_VERSION . ")\n");
    exit(1);
}

// ── CLI-Kontext ───────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// ── Pfade ─────────────────────────────────────────────────────────────────
$PROJECT_ROOT = dirname(__DIR__);
$INSTALL_DIR  = __DIR__;
$LOCK_FILE    = $INSTALL_DIR . '/.lock';

// ── Bereits installiert? ──────────────────────────────────────────────────
if (file_exists($LOCK_FILE)) {
    fwrite(STDERR, "TowerDNS is already installed (.lock file exists).\n");
    fwrite(STDERR, "Remove $LOCK_FILE to reinstall.\n");
    exit(1);
}

// ── Autoloader ───────────────────────────────────────────────────────────
$autoload = $PROJECT_ROOT . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found.\n");
    fwrite(STDERR, "Run: composer install --no-dev\n");
    exit(1);
}
require_once $autoload;

// ── Extensions ───────────────────────────────────────────────────────────
$requiredExts = ['pdo', 'openssl', 'sodium', 'intl', 'mbstring'];
foreach ($requiredExts as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "Missing required PHP extension: $ext\n");
        exit(1);
    }
}

// ── config/ beschreibbar? ─────────────────────────────────────────────────
$cfgDir = $PROJECT_ROOT . '/config';
if (!is_dir($cfgDir) && !mkdir($cfgDir, 0750, true)) {
    fwrite(STDERR, "Cannot create directory: $cfgDir\n");
    exit(1);
}
if (!is_writable($cfgDir)) {
    fwrite(STDERR, "config/ directory is not writable: $cfgDir\n");
    exit(1);
}

// ──────────────────────────────────────────────────────────────────────────
// Helper-Funktionen (CLI)
// ──────────────────────────────────────────────────────────────────────────

/** Fragt eine Eingabe per CLI ab, mit optionalem Default-Wert. */
function cliPrompt(string $question, string $default = '', bool $required = true): string
{
    $hint  = $default !== '' ? " [$default]" : '';
    $hint .= $required && $default === '' ? ' (required)' : '';

    while (true) {
        echo $question . $hint . ': ';
        $input = trim((string) fgets(STDIN));

        if ($input === '' && $default !== '') {
            return $default;
        }
        if ($input !== '' || !$required) {
            return $input;
        }
        echo "  → Value is required.\n";
    }
}

/** Fragt ein Passwort ab (ohne Echo, zweifach). */
function cliPassword(string $question): string
{
    while (true) {
        echo $question . ': ';
        shell_exec('stty -echo 2>/dev/null');
        $pass1 = trim((string) fgets(STDIN));
        shell_exec('stty echo 2>/dev/null');
        echo "\n";

        if (strlen($pass1) < 12) {
            echo "  → Password must be at least 12 characters.\n";
            continue;
        }

        echo 'Repeat password: ';
        shell_exec('stty -echo 2>/dev/null');
        $pass2 = trim((string) fgets(STDIN));
        shell_exec('stty echo 2>/dev/null');
        echo "\n";

        if ($pass1 !== $pass2) {
            echo "  → Passwords do not match.\n";
            continue;
        }
        return $pass1;
    }
}

/** Ja/Nein-Abfrage. */
function cliYesNo(string $question, bool $default = false): bool
{
    $hint = $default ? ' [Y/n]' : ' [y/N]';
    echo $question . $hint . ': ';
    $input = strtolower(trim((string) fgets(STDIN)));

    if ($input === '') {
        return $default;
    }
    return in_array($input, ['y', 'yes', 'j', 'ja'], true);
}

/** Ausgabe-Hilfsfunktion. */
function out(string $msg, string $color = ''): void
{
    $colors = [
        'green'  => "\033[32m",
        'yellow' => "\033[33m",
        'red'    => "\033[31m",
        'bold'   => "\033[1m",
        'reset'  => "\033[0m",
    ];
    $prefix = $colors[$color] ?? '';
    $suffix = $color !== '' ? ($colors['reset'] ?? '') : '';
    echo $prefix . $msg . $suffix . "\n";
}

// ──────────────────────────────────────────────────────────────────────────
// 1) Datenbank-Konfiguration
// ──────────────────────────────────────────────────────────────────────────

out("\n=== TowerDNS CLI Installer ===\n", 'bold');
out("Step 1: Database configuration", 'bold');

$driver = '';
while (!in_array($driver, ['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite'], true)) {
    $driver = cliPrompt('Database driver [pdo_mysql / pdo_pgsql / pdo_sqlite]', 'pdo_mysql');
}

$db = ['driver' => $driver];

if ($driver === 'pdo_sqlite') {
    if (!extension_loaded('pdo_sqlite')) {
        out("Warning: pdo_sqlite extension is not loaded.", 'yellow');
    }
    $defaultPath = $PROJECT_ROOT . '/var/towerdns.sqlite';
    $db['path']  = cliPrompt('SQLite file path', $defaultPath, false) ?: $defaultPath;

    $sqliteDir = dirname($db['path']);
    if (!is_dir($sqliteDir)) {
        mkdir($sqliteDir, 0750, true);
    }
} else {
    $dbPort = $driver === 'pdo_pgsql' ? '5432' : '3306';

    $db['host'] = cliPrompt('Database host', 'localhost');
    $db['port'] = cliPrompt('Database port', $dbPort);
    $db['name'] = cliPrompt('Database name', 'towerdns');
    $db['user'] = cliPrompt('Database user', 'towerdns');

    echo 'Database password (empty = no password): ';
    shell_exec('stty -echo 2>/dev/null');
    $db['pass'] = trim((string) fgets(STDIN));
    shell_exec('stty echo 2>/dev/null');
    echo "\n";

    if ($driver === 'pdo_mysql' && !extension_loaded('pdo_mysql')) {
        out("Warning: pdo_mysql extension is not loaded.", 'yellow');
    }
    if ($driver === 'pdo_pgsql' && !extension_loaded('pdo_pgsql')) {
        out("Warning: pdo_pgsql extension is not loaded.", 'yellow');
    }
}

// ──────────────────────────────────────────────────────────────────────────
// 2) Admin-Konto
// ──────────────────────────────────────────────────────────────────────────

out("\nStep 2: Admin account", 'bold');

$adminUsername = cliPrompt('Admin username', 'admin');
while (!preg_match('/^[a-zA-Z0-9\-_.]{3,50}$/', $adminUsername)) {
    out("  → Username must be 3–50 characters (a-z, 0-9, -, _, .).", 'yellow');
    $adminUsername = cliPrompt('Admin username', 'admin');
}

$adminEmail = cliPrompt('Admin e-mail');
while (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($adminEmail) > 255) {
    out("  → Invalid e-mail address.", 'yellow');
    $adminEmail = cliPrompt('Admin e-mail');
}

$adminPass = cliPassword('Admin password');

// ──────────────────────────────────────────────────────────────────────────
// 3) Anwendungseinstellungen
// ──────────────────────────────────────────────────────────────────────────

out("\nStep 3: Application settings", 'bold');

$appName   = cliPrompt('Application name', 'TowerDNS');
$appDomain = cliPrompt('Domain (optional, e.g. tower.example.com)', '', false);
$appTheme  = cliPrompt('Theme name', 'default');
$appHttps  = cliYesNo('Force HTTPS (HSTS)', true);

// ──────────────────────────────────────────────────────────────────────────
// 4) DNS-Provider
// ──────────────────────────────────────────────────────────────────────────

out("\nStep 4: DNS providers", 'bold');
out("Configure at least one DNS provider.", 'yellow');

$providers = [];

// deSEC
if (cliYesNo('Enable deSEC provider', false)) {
    $token = cliPrompt('deSEC API token');
    if (strlen($token) > 512) {
        $token = substr($token, 0, 512);
    }
    $providers['desec'] = ['token' => $token];
}

// PowerDNS
if (cliYesNo('Enable PowerDNS provider', false)) {
    $pdnsUrl    = cliPrompt('PowerDNS API base URL (e.g. http://localhost:8081)');
    while (filter_var($pdnsUrl, FILTER_VALIDATE_URL) === false) {
        out("  → Invalid URL.", 'yellow');
        $pdnsUrl = cliPrompt('PowerDNS API base URL');
    }
    $pdnsKey    = cliPrompt('PowerDNS API key');
    $pdnsServer = cliPrompt('PowerDNS server ID', 'localhost');
    $providers['powerdns'] = [
        'base_url'  => $pdnsUrl,
        'api_key'   => $pdnsKey,
        'server_id' => $pdnsServer,
    ];
}

// Cloudflare
if (cliYesNo('Enable Cloudflare provider', false)) {
    $cfToken = cliPrompt('Cloudflare API token');
    if (strlen($cfToken) > 512) {
        $cfToken = substr($cfToken, 0, 512);
    }
    $providers['cloudflare'] = ['api_token' => $cfToken];
}

// INWX
if (cliYesNo('Enable INWX provider', false)) {
    $inwxUser = cliPrompt('INWX username');
    $inwxPass = cliPrompt('INWX password');
    $providers['inwx'] = ['username' => $inwxUser, 'password' => $inwxPass];
}

if (empty($providers)) {
    out("No DNS provider configured. Aborting.", 'red');
    exit(1);
}

// ──────────────────────────────────────────────────────────────────────────
// 5) Zusammenfassung + Bestätigung
// ──────────────────────────────────────────────────────────────────────────

out("\n=== Summary ===", 'bold');
out("Database: {$db['driver']}" . ($db['driver'] !== 'pdo_sqlite'
    ? " @ {$db['host']}:{$db['port']}/{$db['name']}" : " → {$db['path']}"));
out("Admin:    $adminUsername <$adminEmail>");
out("App:      $appName" . ($appDomain !== '' ? " ($appDomain)" : '') . " — HTTPS: " . ($appHttps ? 'yes' : 'no'));
out("Providers: " . implode(', ', array_keys($providers)));
echo "\n";

if (!cliYesNo('Start installation?', true)) {
    out("Aborted.", 'yellow');
    exit(0);
}

// ──────────────────────────────────────────────────────────────────────────
// 6) Installation
// ──────────────────────────────────────────────────────────────────────────

out("\nInstalling …", 'green');

// ── Doctrine DBAL-Verbindung ──────────────────────────────────────────────
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

try {
    $conn   = \Doctrine\DBAL\DriverManager::getConnection($params);
    $schema = new \Doctrine\DBAL\Schema\Schema();

    // users
    $tUsers = $schema->createTable('users');
    foreach ([
        ['id',             'integer', ['autoincrement' => true]],
        ['username',       'string',  ['length' => 255]],
        ['password_hash',  'string',  ['length' => 255]],
        ['email',          'string',  ['length' => 255]],
        ['created_at',     'string',  ['length' => 32, 'notnull' => false]],
        ['last_login',     'string',  ['length' => 32, 'notnull' => false]],
        ['is_active',      'boolean', ['default' => true]],
        ['is_admin',       'boolean', ['default' => false]],
        ['totp_secret',    'text',    ['notnull' => false]],
        ['totp_enabled',   'boolean', ['default' => false]],
        ['totp_algorithm', 'string',  ['length' => 16, 'default' => 'sha256']],
        ['totp_digits',    'integer', ['default' => 8]],
        ['theme',          'string',  ['length' => 64, 'default' => 'default']],
        ['locale',         'string',  ['length' => 16, 'default' => 'en']],
    ] as [$col, $type, $opts]) {
        $tUsers->addColumn($col, $type, $opts);
    }
    $tUsers->setPrimaryKey(['id']);
    $tUsers->addUniqueIndex(['username']);
    $tUsers->addUniqueIndex(['email']);

    // api_keys
    $tApiKeys = $schema->createTable('api_keys');
    foreach ([
        ['id',         'integer', ['autoincrement' => true]],
        ['user_id',    'integer', []],
        ['name',       'string',  ['length' => 255]],
        ['api_key',    'string',  ['length' => 255]],
        ['created_at', 'string',  ['length' => 32, 'notnull' => false]],
        ['last_used',  'string',  ['length' => 32, 'notnull' => false]],
        ['is_active',  'boolean', ['default' => true]],
    ] as [$col, $type, $opts]) {
        $tApiKeys->addColumn($col, $type, $opts);
    }
    $tApiKeys->setPrimaryKey(['id']);
    $tApiKeys->addUniqueIndex(['api_key']);
    $tApiKeys->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);

    // user_sessions
    $tSessions = $schema->createTable('user_sessions');
    foreach ([
        ['id',            'integer', ['autoincrement' => true]],
        ['session_token', 'string',  ['length' => 64]],
        ['user_id',       'integer', ['notnull' => false]],
        ['username',      'string',  ['length' => 255, 'default' => '']],
        ['is_valid',      'boolean', ['default' => true]],
        ['is_tls',        'boolean', ['default' => false]],
        ['auth_method',   'string',  ['length' => 32, 'default' => '']],
        ['login_at',      'string',  ['length' => 32, 'notnull' => false]],
        ['valid_until',   'string',  ['length' => 32, 'notnull' => false]],
        ['client_ip',     'string',  ['length' => 45, 'notnull' => false]],
        ['user_agent',    'text',    ['notnull' => false]],
    ] as [$col, $type, $opts]) {
        $tSessions->addColumn($col, $type, $opts);
    }
    $tSessions->setPrimaryKey(['id']);
    $tSessions->addUniqueIndex(['session_token']);
    $tSessions->addIndex(['user_id']);
    $tSessions->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);

    // zones
    $tZones = $schema->createTable('zones');
    foreach ([
        ['id',          'integer', ['autoincrement' => true]],
        ['provider_id', 'string',  ['length' => 64]],
        ['user_id',     'integer', ['notnull' => false]],
        ['zone_name',   'string',  ['length' => 255]],
        ['created_at',  'string',  ['length' => 32, 'notnull' => false]],
    ] as [$col, $type, $opts]) {
        $tZones->addColumn($col, $type, $opts);
    }
    $tZones->setPrimaryKey(['id']);
    $tZones->addUniqueIndex(['zone_name']);
    $tZones->addIndex(['provider_id']);
    $tZones->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'SET NULL']);

    // roles
    $tRoles = $schema->createTable('roles');
    foreach ([
        ['id',          'integer', ['autoincrement' => true]],
        ['name',        'string',  ['length' => 128]],
        ['description', 'text',    ['notnull' => false]],
    ] as [$col, $type, $opts]) {
        $tRoles->addColumn($col, $type, $opts);
    }
    $tRoles->setPrimaryKey(['id']);
    $tRoles->addUniqueIndex(['name']);

    // role_assignments
    $tRoleAssignments = $schema->createTable('role_assignments');
    foreach ([
        ['id',      'integer', ['autoincrement' => true]],
        ['user_id', 'integer', []],
        ['role_id', 'integer', []],
    ] as [$col, $type, $opts]) {
        $tRoleAssignments->addColumn($col, $type, $opts);
    }
    $tRoleAssignments->setPrimaryKey(['id']);
    $tRoleAssignments->addUniqueIndex(['user_id', 'role_id']);
    $tRoleAssignments->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
    $tRoleAssignments->addForeignKeyConstraint('roles', ['role_id'], ['id'], ['onDelete' => 'CASCADE']);

    // Schema ausführen
    out("  Creating database tables …");
    foreach ($schema->toSql($conn->getDatabasePlatform()) as $sql) {
        $conn->executeStatement($sql);
    }

    // Admin-Benutzer
    out("  Inserting admin user …");
    $now  = (new \DateTime())->format('Y-m-d H:i:s');
    $hash = password_hash(
        $adminPass,
        PASSWORD_ARGON2ID,
        ['memory_cost' => 131072, 'time_cost' => 4, 'threads' => 4]
    );
    $conn->insert('users', [
        'username'      => $adminUsername,
        'password_hash' => $hash,
        'email'         => $adminEmail,
        'is_admin'      => 1,
        'is_active'     => 1,
        'created_at'    => $now,
    ]);
    $adminId = (int) $conn->lastInsertId();

    // Admin-Rolle
    $conn->insert('roles', ['name' => 'admin', 'description' => 'Administrator — full access']);
    $adminRoleId = (int) $conn->lastInsertId();
    $conn->insert('role_assignments', ['user_id' => $adminId, 'role_id' => $adminRoleId]);

} catch (\Throwable $e) {
    out("Database error: " . $e->getMessage(), 'red');
    exit(1);
}

// ── Konfigurationsdateien schreiben ───────────────────────────────────────

out("  Writing config files …");

$encKey          = base64_encode(random_bytes(32));
$escapedEncKey   = addcslashes($encKey,       '"\\');
$escapedDomain   = addcslashes($appDomain,    '"\\');
$escapedAppName  = addcslashes($appName,      '"\\');
$escapedAppTheme = addcslashes($appTheme,     '"\\');
$forceHttps      = $appHttps ? 'true' : 'false';

$localToml = <<<TOML
# TowerDNS — Local configuration (auto-generated {$now})
# Never commit this file!

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

// database.toml
if ($db['driver'] === 'pdo_sqlite') {
    $escapedPath = addcslashes($db['path'], '"\\');
    $dbToml = <<<TOML
# TowerDNS — Database configuration (auto-generated {$now})

[database]
driver = "pdo_sqlite"

[database.sqlite]
path = "{$escapedPath}"
TOML;
} else {
    $dbDriver = addcslashes($db['driver'],     '"\\');
    $dbHost   = addcslashes($db['host'],       '"\\');
    $dbPort   = (int) $db['port'];
    $dbName   = addcslashes($db['name'],       '"\\');
    $dbUser   = addcslashes($db['user'],       '"\\');
    $dbPass   = addcslashes($db['pass'] ?? '', '"\\');

    $dbToml = <<<TOML
# TowerDNS — Database configuration (auto-generated {$now})

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

// providers.toml
$providersToml = "# TowerDNS — Provider configuration (auto-generated {$now})\n";
$providersToml .= "# Never commit this file!\n\n";

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

$provTomlFile = $cfgDir . '/providers.toml';
if (file_exists($provTomlFile)) {
    copy($provTomlFile, $provTomlFile . '.bak.' . date('Y-m-d-H-i-s'));
}
file_put_contents($provTomlFile, $providersToml);
chmod($provTomlFile, 0600);

// ── Lock-Datei schreiben ──────────────────────────────────────────────────
file_put_contents($LOCK_FILE, date('Y-m-d H:i:s'));

// ──────────────────────────────────────────────────────────────────────────
// 7) Erfolgsmeldung
// ──────────────────────────────────────────────────────────────────────────

out("\n✓ Installation successful!", 'green');
out("  Admin username : $adminUsername", 'green');
out("  Admin password : $adminPass", 'green');
out("\nPlease write down the password now — it won't be shown again.", 'yellow');
out("Delete the install/ directory for security:\n  rm -rf install/\n", 'yellow');
