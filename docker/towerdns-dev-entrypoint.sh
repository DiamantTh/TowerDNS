#!/bin/sh

set -eu

project_root=/var/www/html
config_dir="$project_root/configs"

mkdir -p "$config_dir" "$project_root/data" "$project_root/logs" "$project_root/cache" "$project_root/install"

: "${TOWERDNS_ENCRYPTION_KEY:?TOWERDNS_ENCRYPTION_KEY must be provided for the development container}"
: "${TOWERDNS_DB_PASSWORD:?TOWERDNS_DB_PASSWORD must be provided for the development container}"
export DB_PASSWORD="$TOWERDNS_DB_PASSWORD"

if [ ! -f "$config_dir/config.local.toml" ]; then
    cat > "$config_dir/config.local.toml" <<EOF
[security]
encryption_key = "$TOWERDNS_ENCRYPTION_KEY"

[app]
domain = "localhost"
base_url = "http://localhost:18080"
force_https = false
debug = true
trusted_proxies = []

[session]
cookie_secure = false

[application]
name = "TowerDNS development"

[theme]
name = "default"
EOF
fi

if [ ! -f "$config_dir/database.toml" ]; then
    cat > "$config_dir/database.toml" <<EOF
[database]
driver = "${TOWERDNS_DB_DRIVER:-pdo_mysql}"
host = "${TOWERDNS_DB_HOST:-mariadb}"
port = ${TOWERDNS_DB_PORT:-3306}
name = "${TOWERDNS_DB_NAME:-towerdns_test}"
user = "${TOWERDNS_DB_USER:-towerdns_app}"
EOF
fi

if [ ! -f "$config_dir/providers.toml" ]; then
    printf '%s\n' '# Development container: provider credentials are configured through the application.' > "$config_dir/providers.toml"
fi

chown -R www-data:www-data "$config_dir" "$project_root/data" "$project_root/logs" "$project_root/cache" "$project_root/install"
chmod 700 "$config_dir" "$project_root/data" "$project_root/logs" "$project_root/cache"
chmod 600 "$config_dir"/*.toml

exec apache2-foreground
