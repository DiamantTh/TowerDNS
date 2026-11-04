#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# Builds a second disposable release against a shared asset directory.
set -eu
cd "$(dirname "$0")/../.."
task_root=$(pwd -P)
task_tmp=$(mktemp -d)
trap 'rm -rf "$task_tmp"' EXIT INT TERM
task_old="$task_tmp/release-old"
task_new="$task_tmp/release-new"
mkdir -p "$task_old/httpdocs" "$task_old/build" "$task_old/src/Infrastructure/Ui"
cp -a themes "$task_old/themes"
cp -a install "$task_old/install"
cp httpdocs/asset.php "$task_old/httpdocs/asset.php"
cp -a scripts "$task_old/scripts"
cp package.json package-lock.json vite.config.ts svelte.config.js tsconfig.json "$task_old/"
cp src/Infrastructure/Ui/AssetManifest.php "$task_old/src/Infrastructure/Ui/"
cp src/Infrastructure/Ui/AssetManifestException.php "$task_old/src/Infrastructure/Ui/"
cp build/asset-manifest.json build/asset-release.json "$task_old/build/"
cp -a httpdocs/assets "$task_tmp/assets"
rm -rf "$task_tmp/assets/.releases"
ln -s "$task_tmp/assets" "$task_old/httpdocs/assets"
ln -s "$task_root/node_modules" "$task_old/node_modules"
(cd "$task_old" && npm run build > "$task_tmp/build-old.log" 2>&1)
cp -a "$task_old" "$task_new"
validate='require "src/Infrastructure/Ui/AssetManifestException.php"; require "src/Infrastructure/Ui/AssetManifest.php"; (new TowerDNS\Infrastructure\Ui\AssetManifest(getcwd()))->resolve();'
activate='require "src/Infrastructure/Ui/AssetManifestException.php"; require "src/Infrastructure/Ui/AssetManifest.php"; (new TowerDNS\Infrastructure\Ui\AssetManifest(getcwd()))->activate();'
(cd "$task_old" && php -r "$activate")
(cd "$task_old" && php -r "$validate")
old_theme=$(php -r '$m=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $m["themes/default/src/app/theme-init.ts"]["file"];' "$task_old/build/asset-manifest.json")
cp "$task_tmp/assets/$old_theme" "$task_tmp/old-theme"
printf '\ndocument.documentElement.dataset.rollingFixture = "new";\n' >> "$task_new/themes/default/src/app/theme-init.ts"
(cd "$task_new" && npm run build > "$task_tmp/build.log" 2>&1)
(cd "$task_old" && php -r "$validate")
(cd "$task_new" && php -r "$activate")
(cd "$task_new" && php -r "$validate")
new_theme=$(php -r '$m=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $m["themes/default/src/app/theme-init.ts"]["file"];' "$task_new/build/asset-manifest.json")
[ "$old_theme" != "$new_theme" ]
cmp "$task_tmp/old-theme" "$task_tmp/assets/$old_theme"
cp "$task_old/build/asset-manifest.json" "$task_tmp/old-manifest"
cp "$task_new/build/asset-manifest.json" "$task_old/build/asset-manifest.json"
if (cd "$task_old" && php -r "$validate" >/dev/null 2>&1); then echo 'Mixed manifest was accepted' >&2; exit 1; fi
cp "$task_tmp/old-manifest" "$task_old/build/asset-manifest.json"
mv "$task_tmp/assets/$new_theme.zst" "$task_tmp/missing-variant"
if (cd "$task_new" && php -r "$activate" >/dev/null 2>&1); then echo 'Incomplete release was accepted' >&2; exit 1; fi
(cd "$task_new" && php -r "$validate")
mv "$task_tmp/missing-variant" "$task_tmp/assets/$new_theme.zst"
(cd "$task_old" && php -r "$validate")
(cd "$task_new" && php -r "$validate")
port=$((22000 + $$ % 30000))
php -S "127.0.0.1:$port" -t "$task_new/httpdocs" > "$task_tmp/server.log" 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true; rm -rf "$task_tmp"' EXIT INT TERM
url="http://127.0.0.1:$port/asset.php?asset="
for retry in 1 2 3 4 5; do curl -fsS -o /dev/null "$url$new_theme" && break; sleep 1; done
for encoding in br zstd identity; do
    curl -fsS -H "Accept-Encoding: $encoding" -o "$task_tmp/body" "$url$old_theme"
    case "$encoding" in br) suffix=.br ;; zstd) suffix=.zst ;; *) suffix= ;; esac
    cmp "$task_tmp/body" "$task_tmp/assets/$old_theme$suffix"
done
mv "$task_tmp/assets/$new_theme.br" "$task_tmp/missing-br"
curl -fsS -H 'Accept-Encoding: br, zstd' -D "$task_tmp/headers" -o "$task_tmp/body" "$url$new_theme"
grep -qi '^Content-Encoding: zstd' "$task_tmp/headers"
cmp "$task_tmp/body" "$task_tmp/assets/$new_theme.zst"
curl -fsS -H 'Accept-Encoding: br' -D "$task_tmp/headers" -o "$task_tmp/body" "$url$new_theme"
if grep -qi '^Content-Encoding:' "$task_tmp/headers"; then echo 'Expected identity fallback' >&2; exit 1; fi
cmp "$task_tmp/body" "$task_tmp/assets/$new_theme"
(cd "$task_new" && php -r 'require "src/Infrastructure/Ui/AssetManifestException.php"; require "src/Infrastructure/Ui/AssetManifest.php"; if ((new TowerDNS\Infrastructure\Ui\AssetManifest(getcwd()))->status() !== "degraded") exit(1);')
mv "$task_tmp/missing-br" "$task_tmp/assets/$new_theme.br"
mv "$task_tmp/assets/$new_theme.zst" "$task_tmp/missing-zst"
curl -fsS -H 'Accept-Encoding: zstd, br' -D "$task_tmp/headers" -o "$task_tmp/body" "$url$new_theme"
grep -qi '^Content-Encoding: br' "$task_tmp/headers"
cmp "$task_tmp/body" "$task_tmp/assets/$new_theme.br"
(cd "$task_new" && php -r 'require "src/Infrastructure/Ui/AssetManifestException.php"; require "src/Infrastructure/Ui/AssetManifest.php"; if ((new TowerDNS\Infrastructure\Ui\AssetManifest(getcwd()))->status() !== "degraded") exit(1);')
mv "$task_tmp/missing-zst" "$task_tmp/assets/$new_theme.zst"
(cd "$task_new" && php -r 'require "src/Infrastructure/Ui/AssetManifestException.php"; require "src/Infrastructure/Ui/AssetManifest.php"; if ((new TowerDNS\Infrastructure\Ui\AssetManifest(getcwd()))->status() !== "healthy") exit(1);')
mv "$task_tmp/assets/$new_theme" "$task_tmp/missing-original"
code=$(curl -s -o /dev/null -w '%{http_code}' "$url$new_theme")
[ "$code" = 503 ]
(cd "$task_new" && php -r 'require "src/Infrastructure/Ui/AssetManifestException.php"; require "src/Infrastructure/Ui/AssetManifest.php"; if ((new TowerDNS\Infrastructure\Ui\AssetManifest(getcwd()))->status() !== "broken") exit(1);')
mv "$task_tmp/missing-original" "$task_tmp/assets/$new_theme"
code=$(curl -s -o /dev/null -w '%{http_code}' "${url}unknown-abcdefgh.js")
[ "$code" = 404 ]
(cd "$task_new" && php -r 'require "src/Infrastructure/Ui/AssetManifestException.php"; require "src/Infrastructure/Ui/AssetManifest.php"; if ((new TowerDNS\Infrastructure\Ui\AssetManifest(getcwd()))->status() !== "healthy") exit(1);')
cp "$task_tmp/assets/$new_theme.br" "$task_tmp/good-br"
printf 'damaged' > "$task_tmp/assets/$new_theme.br"
curl -fsS -H 'Accept-Encoding: br, zstd' -D "$task_tmp/headers" -o "$task_tmp/body" "$url$new_theme"
grep -qi '^Content-Encoding: zstd' "$task_tmp/headers"
(cd "$task_new" && php -r 'require "src/Infrastructure/Ui/AssetManifestException.php"; require "src/Infrastructure/Ui/AssetManifest.php"; if ((new TowerDNS\Infrastructure\Ui\AssetManifest(getcwd()))->status() !== "degraded") exit(1);')
mv "$task_tmp/good-br" "$task_tmp/assets/$new_theme.br"
mv "$task_new/build/asset-activation.json" "$task_tmp/activation"
if (cd "$task_new" && php -r "$validate" >/dev/null 2>&1); then echo 'Missing activation was accepted' >&2; exit 1; fi
mv "$task_tmp/activation" "$task_new/build/asset-activation.json"
(cd "$task_new" && php -r "$validate")
cp "$task_new/httpdocs/asset.php" "$task_tmp/asset-php"
printf '\n// mixed release fixture\n' >> "$task_new/httpdocs/asset.php"
if (cd "$task_new" && php -r "$validate" >/dev/null 2>&1); then echo 'Mixed PHP source accepted' >&2; exit 1; fi
if (cd "$task_new" && php -r "$activate" >/dev/null 2>&1); then echo 'Mixed PHP source activated' >&2; exit 1; fi
cp "$task_tmp/asset-php" "$task_new/httpdocs/asset.php"
(cd "$task_new" && php -r "$validate")
old_id=$(php -r '$m=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $m["id"];' "$task_old/build/asset-activation.json")
if (cd "$task_new" && php scripts/prune-frontend-release.php "$old_id" >/dev/null 2>&1); then echo 'Retained release was pruned' >&2; exit 1; fi
php -r '$a=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["assets"]; $b=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR)["assets"]; foreach ($a as $name => $hash) if (($b[$name] ?? null) !== $hash) unlink($argv[3]."/".$name);' "$task_old/build/asset-release.json" "$task_new/build/asset-release.json" "$task_tmp/assets"
(cd "$task_new" && php scripts/prune-frontend-release.php "$old_id")
code=$(curl -s -o /dev/null -w '%{http_code}' "$url$old_theme")
[ "$code" = 404 ]
echo 'Rolling assets passed: old and new releases coexist; mixed and incomplete releases fail closed.'
