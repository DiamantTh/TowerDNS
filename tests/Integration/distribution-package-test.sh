#!/bin/sh

# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
tmp=$(mktemp -d "${TMPDIR:-/tmp}/towerdns-package-test.XXXXXX")
archive="$tmp/towerdns.tar.gz"
http_pid=
wrong_root_pid=
cleanup() {
    [ -z "$http_pid" ] || kill "$http_pid" 2>/dev/null || true
    [ -z "$wrong_root_pid" ] || kill "$wrong_root_pid" 2>/dev/null || true
    rm -rf "$tmp"
}
trap cleanup EXIT HUP INT TERM

cd "$root"
npm run package:distribution -- "$archive"
mkdir "$tmp/unpacked"
tar -xzf "$archive" -C "$tmp/unpacked"
app="$tmp/unpacked/towerdns"

test -f "$app/vendor/autoload.php"
test -f "$app/build/asset-manifest.json"
test -f "$app/build/asset-activation.json"
release_id=$(php -r '$m=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $m["id"];' "$app/build/asset-activation.json")
test -f "$app/httpdocs/assets/.releases/$release_id.json"
test ! -e "$app/node_modules"
test ! -e "$app/tests"
test ! -e "$app/.git"
test -d "$app/configs" && test -d "$app/cache" && test -d "$app/data" && test -d "$app/logs"

manifest="$app/build/asset-manifest.json"
main=$(php -r '$m=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $m["themes/default/src/app/main.ts"]["file"];' "$manifest")
css=$(php -r '$m=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $m["themes/default/src/app/main.ts"]["css"][0];' "$manifest")
test -f "$app/httpdocs/assets/$main"
test -f "$app/httpdocs/assets/$main.br"
test -f "$app/httpdocs/assets/$main.zst"
test -f "$app/httpdocs/assets/$css"
test -f "$app/httpdocs/assets/$css.br"
test -f "$app/httpdocs/assets/$css.zst"

port=$((19000 + $$ % 1000))
cat >"$tmp/router.php" <<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = getenv('TOWERDNS_TEST_ROOT');
$physical = $root . '/httpdocs' . $path;
if (is_file($physical)) return false;
if (preg_match('~^/(login|admin|users|roles|accounts|zones|records|dnssec|profile|settings)\.php$~', $path, $match)) {
    $_GET['entry'] = $match[1];
}
require $root . '/httpdocs/index.php';
PHP
TOWERDNS_TEST_ROOT="$app" php -S "127.0.0.1:$port" -t "$app/httpdocs" "$tmp/router.php" >"$tmp/php-server.log" 2>&1 &
http_pid=$!
installer_code=000
cookies="$tmp/cookies.txt"
touch "$cookies"
for _ in $(seq 1 50); do
    installer_code=$(curl --silent --output "$tmp/install.html" --cookie "$cookies" --cookie-jar "$cookies" --write-out '%{http_code}' \
        "http://127.0.0.1:$port/install.php" || true)
    [ "$installer_code" = '200' ] && break
    sleep 0.1
done
if [ "$installer_code" != '200' ]; then
    printf 'Packaged installer returned HTTP %s. PHP server log:\n' "$installer_code" >&2
    cat "$tmp/php-server.log" >&2
    [ ! -f "$tmp/install.html" ] || head -c 2048 "$tmp/install.html" >&2
    exit 1
fi
grep -q 'name="action" value="step1"' "$tmp/install.html"
installer_csrf=$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$tmp/install.html" | head -n 1)
[ -n "$installer_csrf" ]
code=$(curl --silent --cookie "$cookies" --cookie-jar "$cookies" --output /dev/null --write-out '%{http_code}' \
    --request POST --data 'action=step1' --data-urlencode "csrf_token=$installer_csrf" "http://127.0.0.1:$port/install.php")
[ "$code" = '302' ]
code=$(curl --silent --cookie "$cookies" --cookie-jar "$cookies" --output "$tmp/step2.html" --write-out '%{http_code}' \
    "http://127.0.0.1:$port/install.php")
[ "$code" = '200' ]
installer_csrf=$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$tmp/step2.html" | head -n 1)
password='Q7!mR4#xV9$kL2@tY5'
code=$(curl --silent --cookie "$cookies" --cookie-jar "$cookies" --output "$tmp/step2-post.html" --write-out '%{http_code}' \
    --request POST \
    --data 'action=step2' --data-urlencode "csrf_token=$installer_csrf" \
    --data 'db_driver=pdo_sqlite' --data-urlencode "db_sqlite_path=$app/data/towerdns.sqlite" \
    --data 'admin_user=package-admin' --data 'admin_email=package-admin@example.test' \
    --data-urlencode "admin_pass=$password" --data-urlencode "admin_pass2=$password" \
    --data-urlencode 'app_name=TowerDNS Prepared Package Test' --data 'app_domain=127.0.0.1' \
    --data 'app_theme=default' --data 'provider_desec=1' --data 'prov_desec_token=synthetic-mock-token' \
    "http://127.0.0.1:$port/install.php")
[ "$code" = '302' ] || { cat "$tmp/step2-post.html" >&2; exit 1; }
code=$(curl --silent --cookie "$cookies" --cookie-jar "$cookies" --output "$tmp/step3.html" --write-out '%{http_code}' \
    "http://127.0.0.1:$port/install.php")
[ "$code" = '200' ]
installer_csrf=$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$tmp/step3.html" | head -n 1)
code=$(curl --silent --cookie "$cookies" --cookie-jar "$cookies" --output "$tmp/success.html" --write-out '%{http_code}' \
    --request POST --data 'action=install' --data-urlencode "csrf_token=$installer_csrf" "http://127.0.0.1:$port/install.php")
[ "$code" = '200' ] || { cat "$tmp/success.html" >&2; exit 1; }
grep -q 'Installation successful!' "$tmp/success.html"
test -f "$app/configs/.installed"

code=$(curl --silent --cookie "$cookies" --cookie-jar "$cookies" --output "$tmp/login.html" --write-out '%{http_code}' \
    "http://127.0.0.1:$port/login.php")
[ "$code" = '200' ]
csrf=$(sed -n 's/.*"csrfToken":"\([^"]*\)".*/\1/p' "$tmp/login.html" | head -n 1)
[ -n "$csrf" ]
code=$(curl --silent --cookie "$cookies" --cookie-jar "$cookies" --dump-header "$tmp/login.headers" --output /dev/null --write-out '%{http_code}' \
    --request POST --data 'email=package-admin@example.test' --data-urlencode "password=$password" \
    --data-urlencode "csrf_token=$csrf" "http://127.0.0.1:$port/login.php")
[ "$code" = '302' ]
grep -qi '^Location: /index.php' "$tmp/login.headers"
code=$(curl --silent --cookie "$cookies" --cookie-jar "$cookies" --output "$tmp/dashboard.html" --write-out '%{http_code}' \
    "http://127.0.0.1:$port/index.php")
[ "$code" = '200' ]
grep -q '"page":"dashboard"' "$tmp/dashboard.html"
code=$(curl --silent --cookie "$cookies" --cookie-jar "$cookies" --output "$tmp/locked.html" --write-out '%{http_code}' \
    "http://127.0.0.1:$port/install.php")
[ "$code" = '403' ]
grep -q 'Installer locked' "$tmp/locked.html"

for asset in "$main" "$css"; do
    for encoding in identity br zstd; do
        headers="$tmp/$encoding.headers"
        body="$tmp/$encoding.body"
        if [ "$encoding" = identity ]; then
            curl --silent --show-error --dump-header "$headers" --output "$body" \
                "http://127.0.0.1:$port/asset.php?asset=$asset"
            cmp "$body" "$app/httpdocs/assets/$asset"
        else
            suffix=$([ "$encoding" = br ] && printf br || printf zst)
            curl --silent --show-error --header "Accept-Encoding: $encoding" --dump-header "$headers" --output "$body" \
                "http://127.0.0.1:$port/asset.php?asset=$asset"
            grep -qi "^Content-Encoding: $encoding" "$headers"
            cmp "$body" "$app/httpdocs/assets/$asset.$suffix"
        fi
        grep -qi '^Vary: Accept-Encoding' "$headers"
        grep -qi 'Cache-Control: public, max-age=31536000, immutable' "$headers"
    done
done

kill "$http_pid" 2>/dev/null || true
wait "$http_pid" 2>/dev/null || true
http_pid=

# The same front controller must reject the package root as a webroot.
php -S "127.0.0.1:$((port + 1))" -t "$app" >"$tmp/wrong-root.log" 2>&1 &
wrong_root_pid=$!
for _ in $(seq 1 50); do
    code=$(curl --silent --show-error --output "$tmp/wrong-root.body" --write-out '%{http_code}' \
        "http://127.0.0.1:$((port + 1))/httpdocs/install.php" || true)
    [ "$code" = '503' ] && break
    sleep 0.1
done
[ "$code" = '503' ]
grep -q 'DocumentRoot' "$tmp/wrong-root.body"

printf '%s\n' 'Prepared package fresh SQLite installation, administrator login, installer lock, immutable CSS/JS identity/br/zstd delivery, and wrong-DocumentRoot rejection verified.'
