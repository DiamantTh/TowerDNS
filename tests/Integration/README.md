# Database integration tests

The PHPUnit migration tests always run against a temporary SQLite database.
MariaDB and PostgreSQL are optional backends selected through environment
variables. They must point at disposable databases; the test suite drops all
tables in those databases between scenarios.

The supplied container definition defaults to the database-only test services.
It also contains an optional `app` profile which builds an Apache/PHP
development container; TowerDNS does not require a container runtime outside
this development setup. With a Compose-compatible Podman or Docker plugin:

```sh
podman compose -f tests/Integration/compose.yaml up -d

TOWERDNS_DB_INTEGRATION_DATABASES=mariadb,postgresql \
TOWERDNS_TEST_MARIADB_DSN='mysql://towerdns_app:towerdns_app@127.0.0.1:13306/towerdns_test' \
TOWERDNS_TEST_POSTGRESQL_DSN='pgsql://towerdns_app:towerdns_app@127.0.0.1:15432/towerdns_test' \
vendor/bin/phpunit tests/Integration/SchemaMigrationDatabaseIntegrationTest.php

podman compose -f tests/Integration/compose.yaml down -v
```

To build and start the optional application container (the first build needs
network access for the Composer and npm dependency stages):

```sh
podman compose -f tests/Integration/compose.yaml --profile app up -d --build
curl -I http://127.0.0.1:18080/install.php
curl -I http://127.0.0.1:18080/assets/app.bundle.js
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
