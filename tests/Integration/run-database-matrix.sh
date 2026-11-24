#!/bin/sh

# Runs destructive migration/concurrency tests against SQLite plus isolated
# MariaDB/PostgreSQL services, or explicitly confirmed disposable databases.
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
root=$(CDPATH= cd -- "$script_dir/../.." && pwd)
compose_file="$script_dir/compose.yaml"
backends=${TOWERDNS_DB_MATRIX_BACKENDS:-mariadb,postgresql}
use_existing=${TOWERDNS_DB_MATRIX_USE_EXISTING:-0}
project="towerdns-db-matrix-$(id -u)-$(php -r 'echo bin2hex(random_bytes(4));')"
runtime=
started=0

case "$backends" in
    mariadb|postgresql|mariadb,postgresql|postgresql,mariadb) ;;
    *) printf '%s\n' 'Use TOWERDNS_DB_MATRIX_BACKENDS=mariadb, postgresql, or both.' >&2; exit 2 ;;
esac

if [ "$use_existing" = '1' ]; then
    if [ "${TOWERDNS_TEST_DATABASE_CONFIRMATION:-}" != 'I_UNDERSTAND_TESTS_DROP_ALL_TABLES' ]; then
        printf '%s\n' 'Existing DSNs require explicit confirmation; selected databases are emptied.' >&2
        exit 2
    fi
    case ",$backends," in
        *,mariadb,*) [ -n "${TOWERDNS_TEST_MARIADB_DSN:-}" ] || { printf '%s\n' 'TOWERDNS_TEST_MARIADB_DSN is required.' >&2; exit 2; } ;;
    esac
    case ",$backends," in
        *,postgresql,*) [ -n "${TOWERDNS_TEST_POSTGRESQL_DSN:-}" ] || { printf '%s\n' 'TOWERDNS_TEST_POSTGRESQL_DSN is required.' >&2; exit 2; } ;;
    esac
else
    if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
        runtime=docker
    elif command -v podman >/dev/null 2>&1 && podman compose version >/dev/null 2>&1; then
        runtime=podman
    else
        printf '%s\n' 'No usable Docker Compose or Podman Compose runtime was found. Use existing-DSN mode for installed disposable servers.' >&2
        exit 2
    fi

    mariadb_port=${TOWERDNS_TEST_MARIADB_PORT:-13306}
    postgresql_port=${TOWERDNS_TEST_POSTGRESQL_PORT:-15432}
    case "$mariadb_port:$postgresql_port" in
        *[!0-9:]*|:*|*:) printf '%s\n' 'Database test ports must be numeric.' >&2; exit 2 ;;
    esac
    if [ "$mariadb_port" -lt 1 ] || [ "$mariadb_port" -gt 65535 ] || [ "$postgresql_port" -lt 1 ] || [ "$postgresql_port" -gt 65535 ]; then
        printf '%s\n' 'Database test ports must be between 1 and 65535.' >&2
        exit 2
    fi
    export TOWERDNS_TEST_MARIADB_PORT="$mariadb_port" TOWERDNS_TEST_POSTGRESQL_PORT="$postgresql_port"
    case ",$backends," in
        *,mariadb,*) export TOWERDNS_TEST_MARIADB_DSN="mysql://towerdns_app:towerdns_app@127.0.0.1:$mariadb_port/towerdns_test" ;;
    esac
    case ",$backends," in
        *,postgresql,*) export TOWERDNS_TEST_POSTGRESQL_DSN="pgsql://towerdns_app:towerdns_app@127.0.0.1:$postgresql_port/towerdns_test" ;;
    esac
    export TOWERDNS_TEST_DATABASE_CONFIRMATION=I_UNDERSTAND_TESTS_DROP_ALL_TABLES

    compose() {
        if [ "$runtime" = 'docker' ]; then
            docker compose -p "$project" -f "$compose_file" "$@"
        else
            podman compose -p "$project" -f "$compose_file" "$@"
        fi
    }
    cleanup() {
        if [ "$started" = '1' ]; then
            compose down --volumes --remove-orphans >/dev/null 2>&1 || true
        fi
    }
    trap cleanup EXIT HUP INT TERM

    printf 'Starting isolated test databases with %s (project %s).\n' "$runtime" "$project"
    started=1
    case "$backends" in
        mariadb) compose up -d mariadb ;;
        postgresql) compose up -d postgres ;;
        *) compose up -d mariadb postgres ;;
    esac
    probe_database() {
        php -r '
            require $argv[1] . "/vendor/autoload.php";
            $dsn = getenv($argv[2]);
            if (!is_string($dsn)) { exit(1); }
            $params = new Doctrine\DBAL\Tools\DsnParser(["mysql" => "pdo_mysql", "pgsql" => "pdo_pgsql"])->parse($dsn);
            $connection = Doctrine\DBAL\DriverManager::getConnection($params);
            $connection->fetchOne("SELECT 1");
            $connection->close();
        ' "$root" "$1" >/dev/null 2>&1
    }
    wait_for_databases() {
        case ",$backends," in
            *,mariadb,*) probe_database TOWERDNS_TEST_MARIADB_DSN || return 1 ;;
        esac
        case ",$backends," in
            *,postgresql,*) probe_database TOWERDNS_TEST_POSTGRESQL_DSN || return 1 ;;
        esac
    }

    if ! wait_for_databases; then
        printf '%s\n' 'Database services are not ready yet; waiting up to 60 seconds.'
        ready=0
        attempt=0
        while [ "$attempt" -lt 60 ]; do
            if wait_for_databases; then ready=1; break; fi
            attempt=$((attempt + 1))
            sleep 1
        done
        if [ "$ready" != '1' ]; then
            printf '%s\n' 'Database services did not become ready; verify PDO drivers and inspect isolated Compose logs.' >&2
            compose logs --no-color >&2 || true
            exit 1
        fi
    fi
fi

export TOWERDNS_DB_INTEGRATION_DATABASES="$backends"
cd "$root"
vendor/bin/phpunit tests/Integration/SchemaMigrationDatabaseIntegrationTest.php
vendor/bin/phpunit tests/Integration/IamAuthorizationConcurrencyIntegrationTest.php
