# TowerDNS installation and release checklist

This checklist distinguishes local automated tests from installations that have actually been run. A green unit or mock test is not evidence of a live provider, public DNS, or production deployment.

## Before installation

- **Required:** use the PHP version and extensions declared by `composer.json`; run `composer install --no-dev --classmap-authoritative` from the release directory. The target PHP-FPM runtime must be checked separately from CLI PHP.
- **Required:** prepare production frontend assets in a build environment with the committed `package-lock.json` (`npm ci`, `npm run frontend:check`, then `php scripts/activate-frontend.php`), then include the verified activated assets in the release. The explicit activation step binds the built manifest to the current PHP source inventory. Node is a build dependency, not a runtime requirement.
- **Required:** point the web server document root at `httpdocs/`, not the repository root. Keep `configs/`, `data/`, `logs/`, backups and `vendor/` outside the public document root. Grant write access only to the runtime directories TowerDNS needs.
- **Required:** prepare a dedicated database and least-privileged database user. Do not run an installer against an existing application database unless following a reviewed upgrade procedure.
- **Required:** configure the TowerDNS encryption key and database configuration through the private configuration files. Preserve both separately from database backups. Never include secrets in command arguments, web URLs, logs, source control or public directories.
- **Required for WebAuthn:** use HTTPS, set the RP ID to the DNS hostname and set the expected origin exactly. Validate reverse-proxy trust and forwarded host/protocol handling before allowing registration.
- **Optional:** SMTP, DNS provider accounts, DNSSEC resolver configuration and monitoring are not required to install the application. Configure only the features that will be used.

## Preflight and initialization

Run `php bin/towerdns towerdns:install:check --format=json` to inspect PHP/extensions, Composer autoload and platform-check artifact, private runtime paths, application/database TOML validity, encryption-key presence without revealing it, WebAuthn hostname/HTTPS configuration, timezone, frontend release, and the detected web document root. When `database.toml` selects an existing database, the command performs a bounded connection and read-only version/table-list check. It never creates a SQLite file, initializes schema, changes configuration, or repairs permissions. An absent database configuration is expected before installation and is reported as WARN/SKIP; a present malformed file or unreachable selected database is FAIL. A non-empty database is WARN and must be reviewed before a fresh install. The JSON response includes PASS/WARN/FAIL/SKIP counts. The command can only report its current PHP SAPI; it cannot prove that a separate remote FPM pool has matching PHP/extensions unless run through that runtime.

Use the explicit `towerdns:install` and schema migration commands only after reviewing the target configuration. Confirm that the configured database is the intended empty installation database before initializing it. TowerDNS does not provision external database accounts or DNS provider accounts. After setup, verify that the installer lock is present and that no setup route remains usable.

## Web server and hosting

The application expects the `httpdocs/` directory as the public root and its front controller for application routes. Deploy the included release assets; do not expose repository files, configuration, database files, backups, logs, or Composer internals. Apache/Nginx and PHP-FPM examples must be adapted to the host's actual socket, user, TLS termination, and trusted-proxy arrangement. Subpath hosting is not claimed as supported without a dedicated integration test. Shared hosting is suitable only when it can enforce the same public-root separation, PHP runtime, HTTPS, and private writable data paths.

## Upgrade and recovery

1. Put the application into the operator's maintenance window and stop scheduled monitor/notification commands.
2. Record the current release and run `php bin/towerdns towerdns:upgrade:check --format=json`.
3. Make a database backup in a private mode-0700 directory outside the checkout and webroot. Prefer `towerdns:backup:create <new-directory> --encrypt`; the CLI prompts for a passphrase without echo. Legacy/plaintext bundles require the explicit `--allow-plaintext` acknowledgement and stronger storage/transport protection.
4. Separately secure the original encryption key and private configuration. A database bundle alone cannot restore encrypted provider/TOTP data.
5. Verify the bundle, restore only into a new isolated target, then run migration/schema checks and application smoke tests while external jobs remain stopped.
6. Review notification outbox state before resuming mail delivery. Restoring a database does not restore external DNS provider state and must never trigger historical DNS writes.
7. Resume scheduled jobs only after the operator has checked login, permissions, provider readback and pending notifications.

A code rollback does not roll back database DDL. MariaDB DDL may be partially applied if import fails; keep the target offline, inspect it, and use a fresh isolated database rather than retrying over partial state. Do not claim automatic rollback unless the chosen engine and operation provide it.

## Evidence and current limits

Local automated tests cover SQLite and isolated MariaDB/PostgreSQL migration/concurrency suites. The migration suite models an installation before the final monitoring-notification migration, retaining account membership, encrypted provider values, TOTP data, API-key scope/hash, and monitoring configuration while applying the pending migration. This is a constructed schema fixture based on the current migration chain, not a database dump from a historical Phase 10/11 release. The native backup roundtrip has been run against isolated PostgreSQL 16.15 with matching `pg_dump`/`pg_restore` 16.15, in addition to the existing PostgreSQL 18 and MariaDB 11.4 results. These tests do not prove installation on an arbitrary web host. The PHPUnit live-provider readback test skips without explicit `TOWERDNS_LIVE_DNS_*` test-zone settings. A DNSSEC resolver result is bounded by the configured resolver trust; the DNS diagnostics are not a public DANE TLS handshake. No production deployment or public DNS modification is implied by a successful CI, build, or Git push.
