#!/bin/sh

# SPDX-License-Identifier: AGPL-3.0-or-later

# Runs a real, disposable MariaDB installation through the HTTP wizard. This
# intentionally stays outside PHPUnit: it exercises Apache, sessions, CSRF,
# the installer token, and Compose as well as PHP application code.

set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
compose_file="$script_dir/compose.yaml"
test_project="towerdns-webinstaller-e2e-$$"
base_url="http://127.0.0.1:18081"
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
    [ -f "$body_file" ] && sed -n '1,80p' "$body_file" >&2
    exit 1
}

csrf_token() {
    token=$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$1" | head -n 1)
    [ -n "$token" ] || fail 'CSRF token was not rendered.'
    printf '%s' "$token"
}

request_get() {
    "$curl_bin" --silent --show-error --max-time 120 \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --dump-header "$headers_file" --output "$body_file" --write-out '%{http_code}' \
        "$base_url$1"
}

printf '%s\n' "Starting disposable Compose project $test_project …"
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
    --data 'db_driver=pdo_mysql' \
    --data 'db_host=mariadb' \
    --data 'db_port=3306' \
    --data 'db_name=towerdns_test' \
    --data 'db_user=towerdns_app' \
    --data 'db_pass=towerdns_app' \
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
podman exec "$container_id" php -r '
    $pdo = new PDO("mysql:host=mariadb;port=3306;dbname=towerdns_test;charset=utf8mb4", "towerdns_app", "towerdns_app", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $userId = (string) $pdo->query("SELECT id FROM users WHERE email = \"webadmin@example.test\"")->fetchColumn();
    if ($userId === "") { throw new RuntimeException("installed administrator is missing"); }
    $personal = (int) $pdo->query("SELECT COUNT(*) FROM accounts a JOIN account_memberships m ON m.account_id = a.id WHERE a.account_type = \"personal\" AND a.personal_user_id = " . $pdo->quote($userId) . " AND a.owner_user_id = " . $pdo->quote($userId) . " AND m.user_id = " . $pdo->quote($userId) . " AND m.role = \"owner\"")->fetchColumn();
    $organization = (int) $pdo->query("SELECT COUNT(*) FROM accounts a JOIN account_memberships m ON m.account_id = a.id WHERE a.account_type = \"organization\" AND a.name = \"TowerDNS Web Installer E2E\" AND m.user_id = " . $pdo->quote($userId) . " AND m.role = \"owner\"")->fetchColumn();
    $limits = (int) $pdo->query("SELECT COUNT(*) FROM account_resource_limits l JOIN accounts a ON a.id = l.account_id WHERE a.personal_user_id = " . $pdo->quote($userId) . " OR a.name = \"TowerDNS Web Installer E2E\"")->fetchColumn();
    if ($personal !== 1 || $organization !== 1 || $limits !== 2) { throw new RuntimeException("installed account invariants failed"); }
'

locked_code=$(request_get '/install.php')
[ "$locked_code" = '403' ] || fail "locked installer returned HTTP $locked_code"
grep -q 'Installer locked' "$body_file" || fail 'installer was not locked after successful installation.'

printf '%s\n' 'Web-installer HTTP integration test passed.'
