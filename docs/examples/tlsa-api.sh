#!/bin/sh
set -eu

: "${TOWERDNS_API_URL:?Set the full scoped /api/v1/accounts/.../zones/.../tlsa URL}"
: "${TOWERDNS_API_TOKEN:?Load the API token from a secret store or protected environment}"
: "${TLSA_OWNER:?Set the service owner, for example _443._tcp.example.org}"

case "$TOWERDNS_API_URL" in
    https://*) ;;
    *) echo "TOWERDNS_API_URL must use HTTPS" >&2; exit 2 ;;
esac
case "$TOWERDNS_API_TOKEN" in
    tdns_*) token_hex=${TOWERDNS_API_TOKEN#tdns_} ;;
    *) echo "TOWERDNS_API_TOKEN has an unexpected format" >&2; exit 2 ;;
esac
if [ "${#token_hex}" -ne 64 ]; then
    echo "TOWERDNS_API_TOKEN has an unexpected format" >&2
    exit 2
fi
case "$token_hex" in
    *[!0-9a-f]*) echo "TOWERDNS_API_TOKEN has an unexpected format" >&2; exit 2 ;;
esac
case "$TOWERDNS_API_URL" in
    *\?*|*\#*) echo "TOWERDNS_API_URL must not contain a query or fragment" >&2; exit 2 ;;
esac
case "${TLSA_ADD_JSON:-[]}" in
    \[*\]) ;;
    *) echo "TLSA_ADD_JSON must be a JSON array" >&2; exit 2 ;;
esac
case "${TLSA_REMOVE_JSON:-[]}" in
    \[*\]) ;;
    *) echo "TLSA_REMOVE_JSON must be a JSON array" >&2; exit 2 ;;
esac

if [ -z "${TLSA_CERT_FILE:-}" ] && [ "${TLSA_ADD_JSON:-[]}" = '[]' ] && [ "${TLSA_REMOVE_JSON:-[]}" = '[]' ]; then
    echo "Set TLSA_CERT_FILE, TLSA_ADD_JSON, or TLSA_REMOVE_JSON" >&2
    exit 2
fi
if [ -n "${TLSA_CERT_FILE:-}" ] && [ ! -r "$TLSA_CERT_FILE" ]; then
    echo "TLSA_CERT_FILE is not readable" >&2
    exit 2
fi
if [ -n "${TLSA_CERT_FILE:-}" ] && grep -q -- '-----BEGIN .*PRIVATE KEY-----' "$TLSA_CERT_FILE"; then
    echo "TLSA_CERT_FILE must contain public certificates only; private keys are not accepted" >&2
    exit 2
fi
command -v curl >/dev/null 2>&1 || { echo "curl is required" >&2; exit 2; }
command -v jq >/dev/null 2>&1 || { echo "jq is required" >&2; exit 2; }
owner_encoded=$(jq -rn --arg owner "$TLSA_OWNER" '$owner | @uri')
api_url=${TOWERDNS_API_URL}?owner=${owner_encoded}

umask 077
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT HUP INT TERM
curl_config=$tmp/curl.conf
get_headers=$tmp/get.headers
get_body=$tmp/get.json
payload=$tmp/change.json
response=$tmp/response.json
printf 'header = "Authorization: Bearer %s"\n' "$TOWERDNS_API_TOKEN" >"$curl_config"

get_status=$(curl --config "$curl_config" --silent --show-error \
    --dump-header "$get_headers" --output "$get_body" \
    --write-out '%{http_code}' "$api_url") || {
        echo "TowerDNS GET failed at the transport layer; inspect the service, then read current state before retrying." >&2
        exit 1
    }
if [ "$get_status" != 200 ]; then
    echo "TowerDNS GET returned HTTP $get_status" >&2
    cat "$get_body" >&2
    exit 1
fi
etag=$(awk 'tolower($1) == "etag:" {gsub("\r", "", $2); value=$2} END {print value}' "$get_headers")
case "$etag" in
    '"'????????????????????????????????????????????????????????????????'"') ;;
    *) echo "GET response did not include a valid ETag" >&2; exit 1 ;;
esac

if [ -n "${TLSA_CERT_FILE:-}" ]; then
    jq -cn \
        --rawfile certificates "$TLSA_CERT_FILE" \
        --argjson usage "${TLSA_USAGE:-3}" \
        --argjson selector "${TLSA_SELECTOR:-1}" \
        --argjson matching "${TLSA_MATCHING:-1}" \
        --argjson add "${TLSA_ADD_JSON:-[]}" \
        --argjson remove "${TLSA_REMOVE_JSON:-[]}" \
        '{certificates:$certificates,usage:$usage,selector:$selector,matching:$matching,add:$add,remove:$remove}' >"$payload"
else
    jq -cn --argjson add "${TLSA_ADD_JSON:-[]}" --argjson remove "${TLSA_REMOVE_JSON:-[]}" \
        '{add:$add,remove:$remove}' >"$payload"
fi

post_status=$(curl --config "$curl_config" --silent --show-error \
    --header 'Content-Type: application/json' --header "If-Match: $etag" \
    --data-binary "@$payload" --request POST --output "$response" \
    --write-out '%{http_code}' "$api_url") || {
        echo "TowerDNS POST ended without a known response. Read current state before deciding whether to repeat the idempotent delta." >&2
        exit 1
    }

case "$post_status" in
    200)
        jq '{owner,ttl,records,capabilities,verification}' "$response"
        ;;
    409|412)
        echo "TowerDNS returned HTTP $post_status. Re-read and review the current RRset before submitting a new change." >&2
        cat "$response" >&2
        exit 1
        ;;
    *)
        echo "TowerDNS returned HTTP $post_status" >&2
        cat "$response" >&2
        exit 1
        ;;
esac
