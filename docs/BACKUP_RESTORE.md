# Backup & Restore

This document describes what a complete, restorable TowerDNS backup consists
of, and what happens if pieces are missing. It reflects the current code
(`FreshInstallBootstrapper`, `AtomicConfigurationWriter`, `CredentialService`)
as of this writing — keep it in sync when those change.

## What must be backed up together

A restorable backup **always** needs both of the following, taken together:

1. **The database** (`data/database.sqlite`, or the configured MySQL/
   PostgreSQL database) — accounts, memberships, managed zones, records,
   audit log, and the *encrypted* provider/TOTP secret blobs.
2. **`configs/config.local.toml`** — in particular the `[security]
   encryption_key` value.

`config.local.toml` also contains `database.toml`-independent app settings
(`app.domain`, `app.force_https`, `security.trusted_proxies`, etc.) and, if
present, `configs/database.toml` (DB connection details) and
`configs/providers.toml` (system provider/module config). Back up the whole
`configs/` directory, not just `config.local.toml`, to restore a fully
working instance without having to redo installer choices.

## Why a database dump alone is not enough

Provider account credentials (API tokens, PowerDNS API keys, etc.) and TOTP
secrets are stored **encrypted** in the database, using
`libsodium`/`crypto_secretbox` with the key from `[security] encryption_key`
in `config.local.toml` (see `CredentialService`). Password hashes and
API-key/reset-token hashes are one-way and don't depend on this key, but
they're also useless on their own without the rest of the schema.

If you restore only the database (e.g. from a DB-only backup job) onto a
host with a **different or missing** `config.local.toml`/`encryption_key`,
every encrypted secret becomes permanently undecryptable — not just
temporarily inaccessible. There is no way to recover that data without the
original key.

## Encryption key loss

If the encryption key is genuinely lost (not backed up, and the original
`configs/config.local.toml` is unavailable), TowerDNS **does not**, and must
not, silently generate a new key and carry on: doing so would silently
corrupt every existing encrypted provider/TOTP secret without any error,
which is worse than failing loudly. `CredentialService` requires a
valid, correctly-sized key to be present and will throw rather than
fabricate one.

Recovery in that situation means:

- Provider account credentials (API tokens etc.) must be re-entered by an
  administrator for every configured `ProviderAccount`.
- TOTP must be reset for every user who had it enabled (they will need to
  re-enroll).
- Everything else (accounts, memberships, zones, records, roles, audit log,
  password hashes) is unaffected, since it does not depend on the
  encryption key.

There is intentionally no "regenerate a lost production key" tooling in
this project — that would require either storing a secondary recovery key
(a real key-management feature, out of scope) or accepting silent data
loss, neither of which is an acceptable default.

## Practical backup procedure

1. Stop writes cleanly if possible, or accept a brief point-in-time
   snapshot (SQLite: use a filesystem/VM snapshot or `sqlite3 .backup`;
   MySQL/PostgreSQL: use the standard dump tooling for that engine).
2. Back up the **entire** `configs/` directory (`config.local.toml`,
   `database.toml`, `providers.toml`) alongside the database snapshot, as one
   consistent set.
3. Store both in the same backup destination/retention policy — a database
   snapshot without its matching `configs/` snapshot from the same point in
   time is not sufficient (see above).
4. Treat the backup archive itself as sensitive: it contains the encryption
   key and (indirectly, via the DB) every encrypted secret. Restrict access
   and encrypt the backup at rest/in transit like any other credential
   store.

## Restore procedure

1. Provision a host meeting the requirements in `README.md` (PHP 8.4+,
   required extensions — see `composer.json`'s `require` section and
   `/health`).
2. Restore `configs/config.local.toml`, `configs/database.toml` and
   `configs/providers.toml` from the backup, with the same file permissions
   (owner-readable only) `AtomicConfigurationWriter` originally set (0600).
3. Restore the database (SQLite file, or import the MySQL/PostgreSQL dump)
   from the matching backup.
4. Ensure the installer lock file (`install/.lock`) exists so the installer
   does not attempt to bootstrap a fresh instance over the restored data.
5. Start the application and check `GET /health` and `GET /ready` to confirm
   the database, config, encryption key, and module/schema state are all
   healthy before directing traffic to the restored instance.
