# Database integration tests

The PHPUnit migration tests always run against a temporary SQLite database.
MariaDB and PostgreSQL are optional backends selected through environment
variables. These integration suites drop every table in their selected
database. External DSNs are therefore rejected unless the database name ends
in `_test`, `_testing`, or `_integration` and the operator explicitly sets
`TOWERDNS_TEST_DATABASE_CONFIRMATION=I_UNDERSTAND_TESTS_DROP_ALL_TABLES`.
Use a dedicated test user with access only to the disposable database.

### Reproducible database matrix

`run-database-matrix.sh` starts only the selected database services using
Docker Compose or Podman Compose. It creates a unique Compose project, uses
the dedicated `towerdns_test` databases and restricted test users, waits for
DBAL connections, runs migration and IAM concurrency integration suites, and
removes only that project's containers and volumes. SQLite runs in PHPUnit as
well. No container runtime is required when disposable MariaDB/PostgreSQL
servers are already installed:

```sh
sh tests/Integration/run-database-matrix.sh
TOWERDNS_DB_MATRIX_BACKENDS=mariadb sh tests/Integration/run-database-matrix.sh
TOWERDNS_DB_MATRIX_BACKENDS=postgresql sh tests/Integration/run-database-matrix.sh
```

To use already running local/test servers, provide the DSN(s) for the selected
backend(s) and make the destructive intent explicit. Credentials are not
printed by the runner:

```sh
TOWERDNS_DB_MATRIX_USE_EXISTING=1 \
TOWERDNS_DB_MATRIX_BACKENDS=mariadb,postgresql \
TOWERDNS_TEST_MARIADB_DSN='mysql://test-user:password@127.0.0.1:3306/towerdns_test' \
TOWERDNS_TEST_POSTGRESQL_DSN='pgsql://test-user:password@127.0.0.1:5432/towerdns_integration' \
TOWERDNS_TEST_DATABASE_CONFIRMATION=I_UNDERSTAND_TESTS_DROP_ALL_TABLES \
sh tests/Integration/run-database-matrix.sh
```

The database-name suffix and confirmation are safety rails, not a replacement
for isolated test credentials. Never point these tests at production. If the
host disallows its container runtime, use an installed disposable database or
report that backend as not run. A missing driver or service is never reported
as a pass.

The supplied container definition has database-only services and an optional
`app` profile which builds an Apache/PHP development container; TowerDNS does
not require a container runtime outside this development setup.

To build and start the optional application container (the first build needs
network access for the Composer and npm dependency stages):

```sh
podman compose -f tests/Integration/compose.yaml --profile app up -d --build
curl -I http://127.0.0.1:18080/install.php
curl -I http://127.0.0.1:18080/login.php
podman compose -f tests/Integration/compose.yaml --profile app stop
```

The app container uses named volumes for its development configuration and
runtime directories. `down` keeps those volumes; use `down -v` only when the
disposable development state should be removed. The default app configuration
targets MariaDB. PostgreSQL remains available for the integration suite and
can be selected by changing the app service's `TOWERDNS_DB_*` environment
values before first startup. Because the configuration is persisted in a
named volume, remove that volume with `down -v` before switching an existing
app volume to a different database target.

### Disposable web-installer HTTP test

The complete HTTP installation, login, dashboard, and logout flow is covered
against MariaDB, PostgreSQL, and SQLite by the same isolated Compose test. It
uses `curl` with a cookie jar against the Apache/PHP container, not a graphical
browser automation framework:

```sh
sh tests/Integration/web-installer-http-test.sh mariadb
sh tests/Integration/web-installer-http-test.sh postgresql
sh tests/Integration/web-installer-http-test.sh sqlite
```

The database argument defaults to `mariadb`, preserving the original command.
The script builds the optional `webinstaller` profile from the current
checkout, follows the installer redirects and forms with a cookie jar, verifies
CSRF rejection and completion, and checks the installation marker and
generated administrator, personal account, organization membership, and
resource limits. It then signs in with that administrator, verifies the
personal active-account scope and dashboard bootstrap, and checks
CSRF-protected logout and post-logout access control. Each run creates a
dynamically named Compose project and deletes only that project's containers
and volumes on exit; it never removes the normal `app` development volumes.
The same HTTP run also checks virtual PHP page URLs without physical wrapper
files, the Apache rewrite, parameter and forged-entry rejection, authentication
redirects, a legacy path route, CSRF, and asset delivery. Virtual PHP pages
require the webserver rewrite; index.php and install.php are physical files.
For the MariaDB run, the test provisions a synthetic TOTP factor, exercises
action-bound step-up through the real HTTP/session/CSRF flow, verifies role
creation and starting an administrator switch are blocked before step-up, and
then checks the effective identity, target-user permissions, and return to the
original administrator. The disposable Apache image is asserted to run PHP
8.4; the host PHP version is not treated as a PHP 8.4 compatibility result.
This is an automated container test, not validation on a real shared-hosting
account or with a physical authenticator.

`IamAuthorizationConcurrencyIntegrationTest` uses `pcntl_fork()` and separate
database connections to race removal of two remaining superadmin role
assignments and concurrent deactivation of both superadmins. SQLite runs on a
temporary file. The matrix runner executes these scenarios against each
selected database backend after the migration suite:

```sh
sh tests/Integration/run-database-matrix.sh
```

The MariaDB service and restricted `towerdns_app` PostgreSQL role are used for
their respective runs. SQLite stores its test file at
`/var/www/html/data/towerdns-webinstaller.sqlite` inside the run's dedicated
`webinstaller_data` volume, outside the public document root. The SQLite run
checks application-user access and foreign-key enforcement, restarts the app
container, then verifies the installed user and personal account remain in the
database. The dynamically named Compose project cleanup removes only that
run's volumes; it does not touch normal development volumes.

The MariaDB account is scoped to the test database. PostgreSQL uses the
`towerdns_app` role created by `postgres-init.sql`; it cannot create databases.
The SQLite run has no server credentials. No production credentials are used.
When the optional profile is not needed, start only the database services as
shown above.

The frontend asset checks can also run without containers after `npm run build`:

```sh
npm run frontend:verify
sh tests/Integration/asset-http-test.sh
sh tests/Integration/rolling-assets-test.sh
```

The HTTP check uses a temporary local PHP server. The rolling check builds a
second disposable release against a shared asset directory and verifies both
old and new releases as well as rejected incomplete or mixed metadata.

### Read-only live DNS provider TLSA readback

`LiveProviderTlsaReadbackIntegrationTest` can query a disposable provider test
zone using real credentials without writing or deleting DNS data. It is skipped
unless every variable below is set. Use a provider token limited to reading the
zone and a dedicated test owner containing `phase9a-live-test`:

```sh
TOWERDNS_LIVE_DNS_PROVIDER=cloudflare \
TOWERDNS_LIVE_DNS_CREDENTIALS_JSON='{"api_token":"..."}' \
TOWERDNS_LIVE_DNS_ZONE_ID='provider-zone-id' \
TOWERDNS_LIVE_DNS_ZONE_NAME='dns-test.example.net' \
TOWERDNS_LIVE_DNS_TLSA_OWNER='_443._tcp.phase9a-live-test' \
TOWERDNS_LIVE_DNS_EXPECTED_TLSA_JSON='["3 1 1 <64-hex-character-association>"]' \
TOWERDNS_LIVE_DNS_READ_ONLY_CONFIRMATION=I_AUTHORIZE_READ_ONLY_TEST_ZONE \
vendor/bin/phpunit tests/Integration/LiveProviderTlsaReadbackIntegrationTest.php
```

Credential JSON is read from the process environment and must match the
selected provider module's schema. The integration checks only the provider
API's TLSA RRset readback and canonical RDATA. It does not write records, query
public DNS, validate DNSSEC, or perform DANE authentication. If no disposable
provider account is configured, the test remains skipped; a skip is not a live
provider pass.

### Prepared distribution package

`npm run package:distribution` builds `dist/towerdns-<commit>.tar.gz` with
production Composer dependencies and a fully activated frontend. It requires
the local build tools but the install target does not. The distribution smoke
test extracts that archive and checks the payload and installer/asset HTTP
responses:

```sh
sh tests/Integration/distribution-package-test.sh
```

The smoke test performs a fresh SQLite install from the extracted package,
administrator password login, dashboard rendering, installer-lock check, and
HTTP delivery of hashed JavaScript and CSS as identity, Brotli, and Zstandard.
It also confirms rejection of a wrong DocumentRoot. The package-specific run
uses PHP's built-in server. The separate `web-installer-http-test.sh` suite
uses Apache/PHP 8.4 and performs HTTP installs with SQLite, MariaDB, and
PostgreSQL. The Nginx/PHP-FPM configuration is documented but was not executed
in an Nginx runtime in this environment. No real shared-hosting account or
physical WebAuthn authenticator was available for testing.

### Native Backup- und Restore-Proben

`tests/Integration/DatabaseBackupIntegrationTest.php` prüft SQLite lokal.
Für die native Probe pro Servertyp zwei leere, getrennte Testdatenbanken
bereitstellen. Die Quellnamen müssen `towerdns_backup_<name>_test` lauten und
die Zielnamen `towerdns_restore_<name>_test`. Setze die DSNs für beide über
`TOWERDNS_BACKUP_MARIADB_SOURCE_DSN` und `TOWERDNS_BACKUP_MARIADB_TARGET_DSN`
beziehungsweise `TOWERDNS_BACKUP_POSTGRESQL_SOURCE_DSN` und
`TOWERDNS_BACKUP_POSTGRESQL_TARGET_DSN`. Setze zusätzlich
`TOWERDNS_BACKUP_TEST_CONFIRM=isolated-empty-databases`. Verwende die
passenden nativen Clients im PATH. Beide Datenbanken bleiben nach der Probe
für die Kontrolle erhalten und müssen vor einem erneuten Lauf neu eingerichtet
werden. Ohne Freigabe und DSNs werden die beiden nativen Tests übersprungen.

```sh
vendor/bin/phpunit tests/Integration/DatabaseBackupIntegrationTest.php --display-skipped
```


The Compose readiness probe uses DBAL's DSN parser before creating a connection.
PostgreSQL round trips require `pg_dump` and `pg_restore` matching the server
major version. SQLite concurrency checks use two processes and require
`ext-pcntl`. SMTP regression tests use a local transport double. Live provider
tests need separately authorised credentials and a dedicated test zone.
