#!/bin/sh

# SPDX-License-Identifier: AGPL-3.0-or-later

# Runs a real, disposable MariaDB, PostgreSQL, or SQLite installation through
# the HTTP wizard. This stays outside PHPUnit: it exercises Apache, sessions,
# CSRF, the installer token, and Compose as well as PHP application code.

set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
compose_file="$script_dir/compose.yaml"
database=${1:-mariadb}
case "$database" in
    mariadb)
        db_driver=pdo_mysql
        db_host=mariadb
        db_port=3306
        db_name=towerdns_test
        db_user=towerdns_app
        db_pass=towerdns_app
        db_path=
        pdo_driver=mysql
        db_host_field=db_host
        db_port_field=db_port
        db_name_field=db_name
        db_user_field=db_user
        db_pass_field=db_pass
        ;;
    postgresql)
        db_driver=pdo_pgsql
        db_host=postgres
        db_port=5432
        db_name=towerdns_test
        db_user=towerdns_app
        db_pass=towerdns_app
        db_path=
        pdo_driver=pgsql
        db_host_field=pg_host
        db_port_field=pg_port
        db_name_field=pg_name
        db_user_field=pg_user
        db_pass_field=pg_pass
        ;;
    sqlite)
        db_driver=pdo_sqlite
        pdo_driver=sqlite
        db_host=
        db_port=
        db_name=
        db_user=
        db_pass=
        db_path=/var/www/html/data/towerdns-webinstaller.sqlite
        db_host_field=db_host
        db_port_field=db_port
        db_name_field=db_name
        db_user_field=db_user
        db_pass_field=db_pass
        ;;
    *)
        printf 'Unsupported web-installer test database: %s (use mariadb, postgresql, or sqlite).\n' "$database" >&2
        exit 2
        ;;
esac
test_project="towerdns-webinstaller-${database}-e2e-$$"
# Keep the request host aligned with the domain entered in the installer. A
# session cookie configured for localhost is intentionally not sent to the
# distinct 127.0.0.1 host.
base_url="http://localhost:18081"
temporary_dir=$(mktemp -d "${TMPDIR:-/tmp}/towerdns-webinstaller-e2e.XXXXXX")
cookie_jar="$temporary_dir/cookies.txt"
headers_file="$temporary_dir/headers.txt"
body_file="$temporary_dir/body.html"
: > "$cookie_jar"
curl_bin=$(command -v curl || true)

if [ -z "$curl_bin" ] && [ -x /usr/sbin/curl ]; then
    curl_bin=/usr/sbin/curl
fi
if [ -z "$curl_bin" ]; then
    printf '%s\n' 'curl is required for the web-installer HTTP integration test.' >&2
    exit 1
fi

compose() {
    podman compose -p "$test_project" -f "$compose_file" "$@"
}

cleanup() {
    compose down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$temporary_dir"
}
trap cleanup EXIT HUP INT TERM

fail() {
    printf 'Web-installer HTTP test failed: %s\n' "$1" >&2
    [ -f "$body_file" ] && head -c 4096 "$body_file" >&2
    printf '\n' >&2
    if [ -n "${container_id:-}" ]; then
        podman exec "$container_id" sh -c 'tail -n 100 /var/log/apache2/error.log 2>/dev/null || true' >&2 || true
    fi
    compose --profile webinstaller logs --no-color webinstaller >&2 || true
    exit 1
}

csrf_token() {
    token=$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$1" | head -n 1)
    [ -n "$token" ] || fail 'CSRF token was not rendered.'
    printf '%s' "$token"
}

bootstrap_csrf_token() {
    token=$(sed -n 's/.*"csrfToken":"\([^"]*\)".*/\1/p' "$1" | head -n 1)
    [ -n "$token" ] || fail 'CSRF token was not provided in the page bootstrap.'
    printf '%s' "$token"
}

request_get() {
    "$curl_bin" --silent --show-error --max-time 120 \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --dump-header "$headers_file" --output "$body_file" --write-out '%{http_code}' \
        "$base_url$1"
}

printf '%s\n' "Starting disposable $database Compose project $test_project …"
# Build the checked-out source before starting. Podman's content-addressed
# cache keeps unchanged dependency layers fast while invalidating COPY layers
# when application source changes.
compose --profile webinstaller build webinstaller
compose --profile webinstaller up -d

for attempt in $(seq 1 60); do
    # Do not probe install.php here: its first visitor deliberately receives
    # the token-authenticated session. The root redirect is sufficient to
    # prove Apache is ready without consuming that installer session.
    if "$curl_bin" --silent --show-error --max-time 2 --output /dev/null "$base_url/"; then
        break
    fi
    [ "$attempt" -lt 60 ] || fail 'webinstaller service did not become reachable.'
    sleep 1
done

root_code=$("$curl_bin" --silent --show-error --max-time 30 --dump-header "$headers_file" --output "$body_file" --write-out '%{http_code}' "$base_url/")
[ "$root_code" = '302' ] || fail "expected initial root redirect, got HTTP $root_code"
grep -qi '^location: /install.php' "$headers_file" || fail 'root did not redirect to /install.php.'

step1_code=$(request_get '/install.php')
[ "$step1_code" = '200' ] || fail "expected installer step 1, got HTTP $step1_code"
grep -q 'name="action" value="step1"' "$body_file" || fail 'installer step 1 form was not rendered.'
grep -q 'towerdns_installer' "$cookie_jar" || fail 'installer session cookie was not created.'
step1_csrf=$(csrf_token "$body_file")

invalid_csrf_code=$("$curl_bin" --silent --show-error --max-time 30 \
    --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers_file" --output "$body_file" --write-out '%{http_code}' \
    --request POST --data 'action=step1' --data 'csrf_token=invalid' "$base_url/install.php")
[ "$invalid_csrf_code" = '400' ] || fail "invalid CSRF token was accepted with HTTP $invalid_csrf_code"
grep -q 'Invalid request.' "$body_file" || fail 'invalid CSRF response was not explicit.'

step1_post_code=$("$curl_bin" --silent --show-error --max-time 30 \
    --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers_file" --output "$body_file" --write-out '%{http_code}' \
    --request POST --data 'action=step1' --data-urlencode "csrf_token=$step1_csrf" "$base_url/install.php")
[ "$step1_post_code" = '302' ] || fail "installer step 1 did not advance, got HTTP $step1_post_code"

step2_code=$(request_get '/install.php')
[ "$step2_code" = '200' ] || fail "expected installer step 2, got HTTP $step2_code"
grep -q 'name="action" value="step2"' "$body_file" || fail 'installer step 2 form was not rendered.'
step2_csrf=$(csrf_token "$body_file")

admin_password='Q7!mR4#xV9$kL2@tY5'
step2_post_code=$("$curl_bin" --silent --show-error --max-time 60 \
    --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers_file" --output "$body_file" --write-out '%{http_code}' \
    --request POST \
    --data 'action=step2' \
    --data-urlencode "csrf_token=$step2_csrf" \
    --data "db_driver=$db_driver" \
    --data "$db_host_field=$db_host" \
    --data "$db_port_field=$db_port" \
    --data "$db_name_field=$db_name" \
    --data "$db_user_field=$db_user" \
    --data "$db_pass_field=$db_pass" \
    --data-urlencode "db_sqlite_path=$db_path" \
    --data 'admin_user=webadmin' \
    --data 'admin_email=webadmin@example.test' \
    --data-urlencode "admin_pass=$admin_password" \
    --data-urlencode "admin_pass2=$admin_password" \
    --data 'app_name=TowerDNS Web Installer E2E' \
    --data 'app_domain=localhost' \
    --data 'app_theme=default' \
    --data 'provider_desec=1' \
    --data 'prov_desec_token=webinstaller-e2e-disposable-token' \
    "$base_url/install.php")
[ "$step2_post_code" = '302' ] || fail "installer step 2 did not advance, got HTTP $step2_post_code"

step3_code=$(request_get '/install.php')
[ "$step3_code" = '200' ] || fail "expected installer step 3, got HTTP $step3_code"
grep -q 'name="action" value="install"' "$body_file" || fail 'installer confirmation form was not rendered.'
step3_csrf=$(csrf_token "$body_file")

install_code=$("$curl_bin" --silent --show-error --max-time 180 \
    --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers_file" --output "$body_file" --write-out '%{http_code}' \
    --request POST --data 'action=install' --data-urlencode "csrf_token=$step3_csrf" "$base_url/install.php")
[ "$install_code" = '200' ] || fail "installer completion returned HTTP $install_code"
grep -q 'Installation successful!' "$body_file" || fail 'installer success page was not rendered.'

container_id=$(podman ps -aq \
    --filter "label=com.docker.compose.project=$test_project" \
    --filter 'label=com.docker.compose.service=webinstaller')
[ -n "$container_id" ] || fail 'webinstaller container could not be resolved.'
podman exec "$container_id" sh -ceu '
    test -f /var/www/html/configs/.installed
    test -f /var/www/html/install/.lock
    test "$(stat -c %a /var/www/html/install/.install_token)" = 600
    grep -q "\[providers.desec\]" /var/www/html/configs/providers.toml
'
personal_account_id=$(podman exec --user www-data "$container_id" php -r '
    [$driver, $host, $port, $database, $username, $password, $path] = array_slice($argv, 1);
    $dsn = match ($driver) {
        "mysql" => "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",
        "pgsql" => "pgsql:host=$host;port=$port;dbname=$database",
        "sqlite" => "sqlite:$path",
        default => throw new RuntimeException("unsupported PDO driver"),
    };
    $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $userQuery = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $userQuery->execute(["webadmin@example.test"]);
    $userId = (string) $userQuery->fetchColumn();
    if ($userId === "") { throw new RuntimeException("installed administrator is missing"); }
    $personalQuery = $pdo->prepare("SELECT id FROM accounts WHERE account_type = ? AND personal_user_id = ? AND owner_user_id = ?");
    $personalQuery->execute(["personal", $userId, $userId]);
    $personalIds = $personalQuery->fetchAll(PDO::FETCH_COLUMN);
    if (count($personalIds) !== 1) { throw new RuntimeException("personal account is missing or not unique"); }
    $personalId = $personalIds[0];
    $membershipQuery = $pdo->prepare("SELECT COUNT(*) FROM account_memberships WHERE account_id = ? AND user_id = ? AND role = ?");
    $membershipQuery->execute([$personalId, $userId, "owner"]);
    $personalMembership = (int) $membershipQuery->fetchColumn();
    $organizationQuery = $pdo->prepare("SELECT COUNT(*) FROM accounts a JOIN account_memberships m ON m.account_id = a.id WHERE a.account_type = ? AND a.name = ? AND m.user_id = ? AND m.role = ?");
    $organizationQuery->execute(["organization", "TowerDNS Web Installer E2E", $userId, "owner"]);
    $organization = (int) $organizationQuery->fetchColumn();
    $limitsQuery = $pdo->prepare("SELECT COUNT(*) FROM account_resource_limits l JOIN accounts a ON a.id = l.account_id WHERE a.personal_user_id = ? OR a.name = ?");
    $limitsQuery->execute([$userId, "TowerDNS Web Installer E2E"]);
    $limits = (int) $limitsQuery->fetchColumn();
    if ($personalMembership !== 1 || $organization !== 1 || $limits !== 2) { throw new RuntimeException("installed account invariants failed"); }
    echo $personalId;
' "$pdo_driver" "$db_host" "$db_port" "$db_name" "$db_user" "$db_pass" "${db_path:-}")

if [ "$database" = sqlite ]; then
    data_mount=$(podman inspect --format '{{range .Mounts}}{{if eq .Destination "/var/www/html/data"}}{{.Type}} {{.Name}}{{end}}{{end}}' "$container_id")
    case "$data_mount" in
        'volume '*) ;;
        *) fail 'SQLite data directory is not backed by the isolated Compose volume.' ;;
    esac

    podman exec --user www-data "$container_id" php -r '
        $path = $argv[1];
        $realPath = realpath($path);
        $publicPath = realpath("/var/www/html/httpdocs");
        if ($realPath === false || $publicPath === false || str_starts_with($realPath, $publicPath . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("SQLite database is missing or inside the public document root");
        }
        if (!is_file($realPath) || !is_readable($realPath) || !is_writable($realPath) || !is_writable(dirname($realPath))) {
            throw new RuntimeException("SQLite database or its parent directory is not accessible to the application user");
        }

        require "/var/www/html/vendor/autoload.php";
        $container = TowerDNS\Application\ContainerFactory::create("/var/www/html");
        $connection = $container->get(Doctrine\DBAL\Connection::class);
        if ((int) $connection->fetchOne("PRAGMA foreign_keys") !== 1) {
            throw new RuntimeException("SQLite foreign-key enforcement is disabled for the application connection");
        }
        $databaseInfo = $connection->fetchAssociative("PRAGMA database_list");
        $databaseFile = is_array($databaseInfo) ? (string) ($databaseInfo["file"] ?? "") : "";
        if ($databaseFile !== $realPath) {
            throw new RuntimeException("Application connection does not use the installed SQLite database file");
        }
    ' "$db_path"

    podman restart "$container_id" >/dev/null
    for attempt in $(seq 1 60); do
        if "$curl_bin" --silent --show-error --max-time 2 --output /dev/null "$base_url/"; then
            break
        fi
        [ "$attempt" -lt 60 ] || fail 'webinstaller service did not recover after restart.'
        sleep 1
    done
    podman exec --user www-data "$container_id" php -r '
        $pdo = new PDO("sqlite:" . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $user = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $user->execute(["webadmin@example.test"]);
        $personal = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE account_type = ? AND personal_user_id = ?");
        $personal->execute(["personal", $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote("webadmin@example.test"))->fetchColumn()]);
        if ((int) $user->fetchColumn() !== 1 || (int) $personal->fetchColumn() !== 1) {
            throw new RuntimeException("SQLite installation data did not survive the container restart");
        }
    ' "$db_path"
fi

# Svelte forms are created from the server-authorized JSON bootstrap. Exercise
# the login handler with its real session and CSRF state rather than merely
# checking that the login route returns a page.
login_code=$(request_get '/login')
[ "$login_code" = '200' ] || fail "login form returned HTTP $login_code"
grep -q '"page":"login"' "$body_file" || fail 'login page bootstrap was not rendered.'
grep -q 'id="towerdns-page" type="application/json"' "$body_file" || fail 'login page did not provide the Svelte bootstrap.'
login_csrf=$(bootstrap_csrf_token "$body_file")

invalid_login_code=$("$curl_bin" --silent --show-error --max-time 30 \
    --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers_file" --output "$body_file" --write-out '%{http_code}' \
    --request POST --data 'email=webadmin@example.test' --data 'password=incorrect-password' \
    --data-urlencode "csrf_token=$login_csrf" "$base_url/login")
[ "$invalid_login_code" = '401' ] || fail "invalid credentials returned HTTP $invalid_login_code"
grep -q '"page":"login"' "$body_file" || fail 'invalid login did not return the login page.'
login_csrf=$(bootstrap_csrf_token "$body_file")

login_post_code=$("$curl_bin" --silent --show-error --max-time 60 \
    --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers_file" --output "$body_file" --write-out '%{http_code}' \
    --request POST --data 'email=webadmin@example.test' --data-urlencode "password=$admin_password" \
    --data-urlencode "csrf_token=$login_csrf" "$base_url/login")
[ "$login_post_code" = '302' ] || fail "valid credentials returned HTTP $login_post_code"
grep -qi '^location: /[[:space:]]*$' "$headers_file" || fail 'successful login did not redirect to the dashboard.'

dashboard_code=$(request_get '/')
[ "$dashboard_code" = '200' ] || fail "dashboard returned HTTP $dashboard_code after login"
grep -q '"page":"dashboard"' "$body_file" || fail 'dashboard bootstrap was not rendered.'
grep -q '"email":"webadmin@example.test"' "$body_file" || fail 'dashboard bootstrap did not contain the authenticated administrator.'
grep -q '<script src="/assets/theme-init.bundle.js"></script>' "$body_file" || fail 'dashboard did not reference the theme asset.'
grep -q '<script type="module" src="/assets/app.bundle.js"></script>' "$body_file" || fail 'dashboard did not reference the application asset.'
dashboard_csrf=$(bootstrap_csrf_token "$body_file")
if grep -q '"page":"login"' "$body_file"; then
    fail 'dashboard response still rendered the login page.'
fi

zones_code=$(request_get '/zones')
[ "$zones_code" = '200' ] || fail "active-account zone view returned HTTP $zones_code"
grep -q "\"accountId\":$personal_account_id" "$body_file" || fail 'active account was not resolved to the administrator personal account.'
grep -q '"managedZones":\[\]' "$body_file" || fail 'the fresh personal account unexpectedly exposed managed zones.'

invalid_logout_code=$("$curl_bin" --silent --show-error --max-time 30 \
    --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers_file" --output "$body_file" --write-out '%{http_code}' \
    --request POST --data 'csrf_token=invalid' "$base_url/logout")
[ "$invalid_logout_code" = '400' ] || fail "invalid logout CSRF returned HTTP $invalid_logout_code"
grep -q 'Invalid request' "$body_file" || fail 'invalid logout CSRF did not return an explicit error.'

dashboard_code=$(request_get '/')
[ "$dashboard_code" = '200' ] || fail 'invalid logout CSRF unexpectedly ended the authenticated session.'
dashboard_csrf=$(bootstrap_csrf_token "$body_file")

logout_code=$("$curl_bin" --silent --show-error --max-time 30 \
    --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers_file" --output "$body_file" --write-out '%{http_code}' \
    --request POST --data-urlencode "csrf_token=$dashboard_csrf" "$base_url/logout")
[ "$logout_code" = '302' ] || fail "logout returned HTTP $logout_code"
grep -qi '^location: /login[[:space:]]*$' "$headers_file" || fail 'logout did not redirect to login.'

post_logout_dashboard_code=$(request_get '/')
[ "$post_logout_dashboard_code" = '302' ] || fail "dashboard was accessible after logout with HTTP $post_logout_dashboard_code"
grep -qi '^location: /login[[:space:]]*$' "$headers_file" || fail 'dashboard did not redirect to login after logout.'

post_logout_login_code=$(request_get '/login')
[ "$post_logout_login_code" = '200' ] || fail "login form returned HTTP $post_logout_login_code after logout"
grep -q '"page":"login"' "$body_file" || fail 'login page bootstrap was not restored after logout.'

locked_code=$(request_get '/install.php')
[ "$locked_code" = '403' ] || fail "locked installer returned HTTP $locked_code"
grep -q 'Installer locked' "$body_file" || fail 'installer was not locked after successful installation.'

printf '%s\n' "Web-installer HTTP integration test passed for $database."
