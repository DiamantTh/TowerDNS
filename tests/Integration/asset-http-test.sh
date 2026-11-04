#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu
cd "$(dirname "$0")/../.."
task_temp=$(mktemp -d)
task_port=$((22000 + $$ % 30000))
php -S "127.0.0.1:$task_port" -t httpdocs >"$task_temp/server.log" 2>&1 &
task_pid=$!
trap 'kill "$task_pid" 2>/dev/null || true; rm -rf "$task_temp"' EXIT INT TERM
asset=$(php -r '$m=json_decode(file_get_contents("build/asset-manifest.json"), true, 512, JSON_THROW_ON_ERROR); echo $m["themes/default/src/app/main.ts"]["file"];')
css=$(php -r '$m=json_decode(file_get_contents("build/asset-manifest.json"), true, 512, JSON_THROW_ON_ERROR); echo $m["themes/default/src/app/main.ts"]["css"][0];')
url="http://127.0.0.1:$task_port/asset.php?asset=$asset"
for retry in 1 2 3 4 5; do
    if curl -fsS -o /dev/null "$url"; then break; fi
    sleep 1
done
check() { if ! "$@"; then echo "asset HTTP check failed: $*" >&2; exit 1; fi; }
curl -fsS -H 'Accept-Encoding: br, zstd' -D "$task_temp/headers" -o "$task_temp/body" "$url"
check grep -qi '^Content-Encoding: br' "$task_temp/headers"
check grep -qi '^Vary:.*Accept-Encoding' "$task_temp/headers"
check grep -qi '^Cache-Control:.*immutable' "$task_temp/headers"
check cmp "$task_temp/body" "httpdocs/assets/$asset.br"
curl -fsS -H 'Accept-Encoding: zstd, br;q=0' -D "$task_temp/headers" -o "$task_temp/body" "$url"
check grep -qi '^Content-Encoding: zstd' "$task_temp/headers"
check cmp "$task_temp/body" "httpdocs/assets/$asset.zst"
curl -fsS -H 'Accept-Encoding: gzip' -D "$task_temp/headers" -o "$task_temp/body" "$url"
if grep -qi '^Content-Encoding:' "$task_temp/headers"; then echo 'identity response had Content-Encoding' >&2; exit 1; fi
check cmp "$task_temp/body" "httpdocs/assets/$asset"
curl -fsS -H 'Accept-Encoding: br;q=0.2, zstd;q=0.7, identity;q=0.1' -D "$task_temp/headers" -o "$task_temp/body" "$url"
check grep -qi '^Content-Encoding: zstd' "$task_temp/headers"
etag=$(sed -n 's/^ETag: //Ip' "$task_temp/headers" | tr -d '\r')
status=$(curl -sS -H 'Accept-Encoding: zstd' -H "If-None-Match: $etag" -o /dev/null -w '%{http_code}' "$url")
check test "$status" = 304
status=$(curl -sS -H 'Accept-Encoding: identity;q=0, br;q=0, zstd;q=0' -o /dev/null -w '%{http_code}' "$url")
check test "$status" = 406
curl -fsS -H 'Accept-Encoding: zstd' -D "$task_temp/headers" -o "$task_temp/body" "http://127.0.0.1:$task_port/asset.php?asset=$css"
check grep -qi '^Content-Type: text/css' "$task_temp/headers"
check cmp "$task_temp/body" "httpdocs/assets/$css.zst"
echo 'Asset HTTP negotiation passed (br, zstd, identity, q-values, cache, 304, 406, CSS).'
