#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
project="${IPAMFERRY_E2E_PROJECT:-ipamferry-e2e}"
mode="${1:-chromium}"
netbox_seed="${IPAMFERRY_E2E_NETBOX_SEED:-}"
keep_failed="${IPAMFERRY_E2E_KEEP_FAILED:-0}"

cd "$root"

if [[ "$mode" == 'all' ]]; then
  for browser in chromium firefox webkit; do
    IPAMFERRY_E2E_PROJECT="${project}-${browser}" "$0" "$browser"
  done
  exit 0
fi

export IPAMFERRY_BIND_IP=127.0.0.1
export IPAMFERRY_HTTP_PORT="${IPAMFERRY_E2E_HTTP_PORT:-18081}"
export IPAMFERRY_HTTPS_PORT="${IPAMFERRY_E2E_HTTPS_PORT:-18444}"
export IPAMFERRY_E2E_BASE_URL="https://localhost:${IPAMFERRY_HTTPS_PORT}"
export IPAMFERRY_IMAGE="${IPAMFERRY_E2E_IMAGE:-ipamferry:e2e}"

compose=(docker compose --project-name "$project" --file compose.yaml --file compose.dev.yaml --profile sandbox)

cleanup() {
  result=$?
  trap - EXIT

  if [[ "$result" -ne 0 && "$keep_failed" == '1' ]]; then
    exit "$result"
  fi

  "${compose[@]}" down --volumes --remove-orphans
  exit "$result"
}
trap cleanup EXIT

"${compose[@]}" down --volumes --remove-orphans
if [[ -n "$netbox_seed" ]]; then
  [[ -f "$netbox_seed" ]]
  "${compose[@]}" build
  "${compose[@]}" up --detach --wait init sandbox-postgres sandbox-redis
  sandbox_postgres="$("${compose[@]}" ps --quiet sandbox-postgres)"
  docker cp "$netbox_seed" "${sandbox_postgres}:/var/lib/postgresql/ipamferry-e2e-seed.dump"
  "${compose[@]}" exec --no-TTY sandbox-postgres pg_restore \
    --username=netbox \
    --dbname=netbox \
    --clean \
    --if-exists \
    --no-owner \
    /var/lib/postgresql/ipamferry-e2e-seed.dump
  "${compose[@]}" exec --no-TTY sandbox-postgres \
    psql --username=netbox --dbname=netbox --command='TRUNCATE TABLE users_token CASCADE'
  "${compose[@]}" up --detach --wait
else
  "${compose[@]}" up --detach --build --wait
fi

for _ in $(seq 1 45); do
  if curl --fail --insecure --silent --show-error "${IPAMFERRY_E2E_BASE_URL}/up" >/dev/null; then break; fi
  sleep 2
done
curl --fail --insecure --silent --show-error "${IPAMFERRY_E2E_BASE_URL}/up" >/dev/null

export IPAMFERRY_INSTALLATION_TOKEN
IPAMFERRY_INSTALLATION_TOKEN="$("${compose[@]}" exec --no-TTY app php artisan ipamferry:installation-token)"

npx playwright test --project="$mode"
