<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * TowerDNS Installer — English translations (en-GB)
 * Text domain: installer
 */
return [
    '' => [
        'plural_forms' => 'nplurals=2; plural=(n!=1);',
    ],

    // ── Layout / Navigation ───────────────────────────────────────────────
    'layout.title'       => 'Installer',
    'layout.lang_switch' => 'Switch language',
    'layout.to_app'      => 'Go to application',

    'nav.back'    => 'Back',
    'nav.next'    => 'Next',
    'nav.restart' => 'Restart',

    // ── Schritt-Labels ────────────────────────────────────────────────────
    'steps.s1' => 'System Check',
    'steps.s2' => 'Configuration',
    'steps.s3' => 'Confirmation',

    // ── Access denied ─────────────────────────────────────────────────────
    'access.title'             => 'Installer locked',
    'access.protected'         => 'Installer protected by token',
    'access.protected_hint'    => 'Enter the installer token that was automatically created on first access.',
    'access.token_label'       => 'Installer token',
    'access.token_placeholder' => 'Paste token here …',
    'access.unlock'            => 'Unlock',
    'access.invalid_token'     => 'Invalid token. Please check your input.',
    'access.token_retrieve'    => 'Retrieve token on the server:',

    // ── Locked ────────────────────────────────────────────────────────────
    'locked.title'          => 'Installer locked',
    'locked.heading'        => 'Installer locked',
    'locked.body'           => 'TowerDNS is already installed. The installer is therefore disabled.',
    'locked.recommendation' => 'We strongly recommend deleting the entire install/ folder:',

    // ── Step 1: System Check ──────────────────────────────────────────────
    'step1.heading'            => 'System Check',
    'step1.subheading'         => 'The following prerequisites must be fulfilled before installation can proceed.',
    'step1.col_check'          => 'Check',
    'step1.col_status'         => 'Status',
    'step1.col_detail'         => 'Details',
    'step1.required'           => 'Required',
    'step1.ok'                 => 'OK',
    'step1.missing'            => 'Missing',
    'step1.notice'             => 'Notice',
    'step1.vendor_heading'     => 'Composer vendor/ directory missing',
    'step1.vendor_body'        => 'Please run the following command in the project root:',
    'step1.vendor_reload'      => 'Then reload this page.',
    'step1.vendor_missing'     => 'Composer vendor/ directory is missing. Please run: composer install --no-dev',
    'step1.reqs_not_met'       => 'Not all required checks passed. Please resolve the issues before continuing.',
    'step1.btn_next'           => 'Continue to Configuration',
    'step1.btn_disabled_title' => 'All required checks must pass first',

    // ── Step 2: Configuration ─────────────────────────────────────────────
    'step2.heading'    => 'Configuration',
    'step2.subheading' => 'Configure your database, admin account, application settings and DNS providers.',

    'step2.db_section'  => 'Database',
    'step2.db_driver'   => 'Database driver',
    'step2.hostname'    => 'Host',
    'step2.port'        => 'Port',
    'step2.dbname'      => 'Database name',
    'step2.dbuser'      => 'Database user',
    'step2.dbpass'      => 'Database password',
    'step2.db_create'   => 'Create database and user automatically (requires root/superuser access)',
    'step2.root_hint'   => 'Root credentials are used only temporarily to create the database and user. They are never stored.',
    'step2.root_user'   => 'Root user',
    'step2.root_pass'   => 'Root password',
    'step2.sqlite_path' => 'SQLite file path',
    'step2.sqlite_hint' => 'Leave empty to use the default path inside data/.',
    'step2.pgsql_hint'  => 'The database and user must already exist for PostgreSQL.',

    'step2.admin_section'      => 'Admin account',
    'step2.admin_username'     => 'Username',
    'step2.admin_email'        => 'E-mail address',
    'step2.admin_pass'         => 'Password',
    'step2.admin_pass_min'     => 'min. 16 characters',
    'step2.admin_pass_confirm' => 'Repeat password',
    'step2.pass_mismatch_live' => 'Passwords do not match.',

    'step2.app_section'     => 'Application settings',
    'step2.app_name'        => 'Application name',
    'step2.app_domain'      => 'Domain',
    'step2.app_domain_hint' => 'optional',
    'step2.app_domain_help' => 'e.g. tower.example.com — used for cookie domain and HSTS.',
    'step2.app_theme'       => 'Theme',
    'step2.app_https'       => 'Force HTTPS / Strict-Transport-Security',
    'step2.btn_next'        => 'Continue to Confirmation',

    // Provider section
    'step2.provider_section'                 => 'DNS Providers',
    'step2.provider_hint'                    => 'Select at least one DNS provider and enter the corresponding credentials.',
    'step2.provider_desec_token'             => 'deSEC API token',
    'step2.provider_powerdns_url'            => 'PowerDNS API base URL',
    'step2.provider_powerdns_key'            => 'PowerDNS API key',
    'step2.provider_powerdns_server'         => 'Server ID',
    'step2.provider_powerdns_server_default' => 'default: localhost',
    'step2.provider_cloudflare_token'        => 'Cloudflare API token',
    'step2.provider_inwx_user'               => 'INWX username',
    'step2.provider_inwx_pass'               => 'INWX password',

    // Validation errors
    'step2.invalid_driver'                    => 'Invalid database driver selected.',
    'step2.invalid_sqlite_path'               => 'Invalid SQLite path.',
    'step2.dir_create_failed'                 => 'Could not create directory: %s',
    'step2.sqlite_error'                      => 'SQLite connection failed: %s',
    'step2.host_invalid'                      => 'Invalid hostname.',
    'step2.port_invalid'                      => 'Invalid port number (1–65535).',
    'step2.dbname_invalid_63'                 => 'Database name must be 1–63 alphanumeric characters (a-z, 0-9, _).',
    'step2.dbname_invalid_64'                 => 'Database name must be 1–64 alphanumeric characters (a-z, 0-9, _).',
    'step2.dbuser_invalid_63'                 => 'Database user must be 1–63 alphanumeric characters (a-z, 0-9, _).',
    'step2.dbuser_invalid_32'                 => 'Database user must be 1–32 alphanumeric characters (a-z, 0-9, _).',
    'step2.pgsql_error'                       => 'PostgreSQL connection failed: %s',
    'step2.root_user_invalid'                 => 'Invalid root user name.',
    'step2.root_error'                        => 'Error creating database/user: %s',
    'step2.mysql_error'                       => 'MySQL/MariaDB connection failed: %s',
    'step2.admin_user_invalid'                => 'Username must be 3–50 characters (a-z, 0-9, -, _, .).',
    'step2.admin_email_invalid'               => 'Invalid or too long e-mail address.',
    'step2.admin_pass_short'                  => 'Password must be at least 16 characters.',
    'step2.admin_pass_mismatch'               => 'Passwords do not match.',
    'step2.app_name_invalid'                  => 'Application name must be 1–100 characters.',
    'step2.app_domain_invalid'                => 'Invalid domain name (only a-z, 0-9, -, .).',
    'step2.provider_none_selected'            => 'Please configure at least one DNS provider.',
    'step2.provider_desec_token_invalid'      => 'deSEC: API token must not be empty.',
    'step2.provider_powerdns_url_invalid'     => 'PowerDNS: invalid or missing base URL.',
    'step2.provider_powerdns_key_invalid'     => 'PowerDNS: API key must not be empty.',
    'step2.provider_cloudflare_token_invalid' => 'Cloudflare: API token must not be empty.',
    'step2.provider_inwx_user_invalid'        => 'INWX: username must not be empty.',
    'step2.provider_inwx_pass_invalid'        => 'INWX: password must not be empty.',

    // ── Step 3: Confirmation ──────────────────────────────────────────────
    'step3.heading'             => 'Confirm Installation',
    'step3.subheading'          => 'Please review your configuration before starting the installation.',
    'step3.warning'             => 'Existing configuration files will be backed up. Tables will be created in the database.',
    'step3.confirm'             => 'Start installation now?',
    'step3.btn_install'         => 'Install now',
    'step3.session_lost'        => 'Session data lost. Please restart the installer.',
    'step3.install_failed'      => 'Installation failed: %s',
    'step3.provider_configured' => 'Configured ✓',

    // ── Success ───────────────────────────────────────────────────────────
    'success.heading'          => 'Installation successful!',
    'success.admin_user'       => 'Admin username',
    'success.admin_pass'       => 'Admin password',
    'success.pass_warning'     => 'Write down the password now — it will not be shown again!',
    'success.security_hint'    => 'Important',
    'success.security_body'    => 'Delete the install/ folder or use the button below to remove the installer. Leaving it online is a security risk.',
    'success.confirm_delete'   => 'Delete the entire install/ folder?',
    'success.delete_installer' => 'Delete installer now',

    // ── Requirements (helpers.php) ────────────────────────────────────────
    'req.php'             => 'PHP ≥ 8.4',
    'req.php_detail'      => 'Installed: %s',
    'req.ext'             => 'PHP extension: %s',
    'req.loaded'          => 'Loaded ✓',
    'req.missing'         => 'MISSING',
    'req.ok'              => 'OK',
    'req.not_writable'    => 'Not writable!',
    'req.config_writable' => 'configs/ directory writable',
    'req.db_driver'       => 'Database driver',
];
