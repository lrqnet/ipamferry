#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
project="${IPAMFERRY_E2E_PROJECT:-ipamferry-e2e}"
mode="${1:-chromium}"

cd "$root"
export IPAMFERRY_BIND_IP=127.0.0.1
export IPAMFERRY_HTTP_PORT="${IPAMFERRY_E2E_HTTP_PORT:-18081}"
export IPAMFERRY_HTTPS_PORT="${IPAMFERRY_E2E_HTTPS_PORT:-18444}"
export IPAMFERRY_E2E_BASE_URL="https://localhost:${IPAMFERRY_HTTPS_PORT}"
export IPAMFERRY_IMAGE="${IPAMFERRY_E2E_IMAGE:-ipamferry:e2e}"

compose=(docker compose --project-name "$project" --file compose.yaml --file compose.dev.yaml --profile sandbox)

cleanup() {
  result=$?
  trap - EXIT

  if [[ "$result" -ne 0 ]]; then
    mkdir -p test-results
    "${compose[@]}" logs --no-color >test-results/compose.log 2>&1 || true
  fi

  "${compose[@]}" down --volumes --remove-orphans
  exit "$result"
}
trap cleanup EXIT

"${compose[@]}" down --volumes --remove-orphans
"${compose[@]}" up --detach --build --wait

for _ in $(seq 1 45); do
  if curl --fail --insecure --silent --show-error "${IPAMFERRY_E2E_BASE_URL}/up" >/dev/null; then break; fi
  sleep 2
done
curl --fail --insecure --silent --show-error "${IPAMFERRY_E2E_BASE_URL}/up" >/dev/null

export IPAMFERRY_INSTALLATION_TOKEN
IPAMFERRY_INSTALLATION_TOKEN="$("${compose[@]}" exec --no-TTY app php artisan ipamferry:installation-token)"

if [[ "$mode" == 'all' ]]; then npm run test:e2e:all; else npm run test:e2e:chromium; fi
