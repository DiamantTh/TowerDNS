# TowerDNS installation and release checklist

This checklist distinguishes local automated tests from installations that have actually been run. A green unit or mock test is not evidence of a live provider, public DNS, or production deployment.

## Before installation

- **Required:** use the PHP version and extensions declared by `composer.json`; run `composer install --no-dev --classmap-authoritative` from the release directory. The target PHP-FPM runtime must be checked separately from CLI PHP.
- **Required:** prepare production frontend assets in a build environment with the committed `package-lock.json` (`npm ci`, `npm run frontend:check`), then include the verified activated assets in the release. Node is a build dependency, not a runtime requirement.
- **Required:** point the web server document root at `httpdocs/`, not the repository root. Keep `configs/`, `data/`, `logs/`, backups and `vendor/` outside the public document root. Grant write access only to the runtime directories TowerDNS needs.
- **Required:** prepare a dedicated database and least-privileged database user. Do not run an installer against an existing application database unless following a reviewed upgrade procedure.
- **Required:** configure the TowerDNS encryption key and database configuration through the private configuration files. Preserve both separately from database backups. Never include secrets in command arguments, web URLs, logs, source control or public directories.
- **Required for WebAuthn:** use HTTPS, set the RP ID to the DNS hostname and set the expected origin exactly. Validate reverse-proxy trust and forwarded host/protocol handling before allowing registration.
- **Optional:** SMTP, DNS provider accounts, DNSSEC resolver configuration and monitoring are not required to install the application. Configure only the features that will be used.

## Preflight and initialization

Run `php bin/towerdns towerdns:install:check --format=json` to inspect PHP, Composer files, runtime directories, frontend release and the detected web document root. This check is read-only; it does not initialize a database. A WARN means an operator must verify the item. A FAIL blocks installation. The check cannot prove that a remote FPM pool has the same extensions as CLI unless executed in that runtime.

Use the explicit `towerdns:install` and schema migration commands only after reviewing the target configuration. Confirm that the configured database is the intended empty installation database before initializing it. TowerDNS does not provision external database accounts or DNS provider accounts. After setup, verify that the installer lock is present and that no setup route remains usable.

## Web server and hosting

The application expects the `httpdocs/` directory as the public root and its front controller for application routes. Deploy the included release assets; do not expose repository files, configuration, database files, backups, logs, or Composer internals. Apache/Nginx and PHP-FPM examples must be adapted to the host's actual socket, user, TLS termination, and trusted-proxy arrangement. Subpath hosting is not claimed as supported without a dedicated integration test. Shared hosting is suitable only when it can enforce the same public-root separation, PHP runtime, HTTPS, and private writable data paths.

## Upgrade and recovery

1. Put the application into the operator's maintenance window and stop scheduled monitor/notification commands.
2. Record the current release and run `php bin/towerdns towerdns:upgrade:check --format=json`.
3. Make a database backup in a private mode-0700 directory outside the checkout and webroot. Current bundles contain an unencrypted database payload; the CLI requires explicit `--allow-plaintext`. Protect the destination and transfer it through an encrypted channel.
4. Separately secure the original encryption key and private configuration. A database bundle alone cannot restore encrypted provider/TOTP data.
5. Verify the bundle, restore only into a new isolated target, then run migration/schema checks and application smoke tests while external jobs remain stopped.
6. Review notification outbox state before resuming mail delivery. Restoring a database does not restore external DNS provider state and must never trigger historical DNS writes.
7. Resume scheduled jobs only after the operator has checked login, permissions, provider readback and pending notifications.

A code rollback does not roll back database DDL. MariaDB DDL may be partially applied if import fails; keep the target offline, inspect it, and use a fresh isolated database rather than retrying over partial state. Do not claim automatic rollback unless the chosen engine and operation provide it.

## Evidence and current limits

Local automated tests cover SQLite and isolated MariaDB/PostgreSQL migration/concurrency suites. The current automated database matrix uses disposable test containers; it does not prove installation on an arbitrary web host. The PHPUnit live-provider readback test skips without explicit `TOWERDNS_LIVE_DNS_*` test-zone settings. A DNSSEC resolver result is bounded by the configured resolver trust; the DNS diagnostics are not a public DANE TLS handshake. No production deployment or public DNS modification is implied by a successful CI, build, or Git push.
