# Database integration tests

The PHPUnit migration tests always run against a temporary SQLite database.
MariaDB and PostgreSQL are optional backends selected through environment
variables. They must point at disposable databases; the test suite drops all
tables in those databases between scenarios.

The supplied container definition exposes only loopback ports and contains no
TowerDNS runtime services. With a Compose-compatible Podman or Docker plugin:

```sh
podman compose -f tests/Integration/compose.yaml up -d

TOWERDNS_DB_INTEGRATION_DATABASES=mariadb,postgresql \
TOWERDNS_TEST_MARIADB_DSN='mysql://towerdns_app:towerdns_app@127.0.0.1:13306/towerdns_test' \
TOWERDNS_TEST_POSTGRESQL_DSN='pgsql://towerdns_app:towerdns_app@127.0.0.1:15432/towerdns_test' \
vendor/bin/phpunit tests/Integration/SchemaMigrationDatabaseIntegrationTest.php

podman compose -f tests/Integration/compose.yaml down -v
```

The MariaDB account is scoped to the existing test database. PostgreSQL uses
the `towerdns_app` role created by `postgres-init.sql`; it cannot create
databases. No production credentials or persistent application volumes are
used. When a Compose plugin is unavailable, the same image definitions can be
started with equivalent `podman run` commands and the same environment
variables.
