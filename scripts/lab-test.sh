#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version="${1:-v1.8.1}"
netbox_image="${2:-netboxcommunity/netbox:v4.6.1-5.0.1}"
primary_family="${IPAMFERRY_LAB_PRIMARY_FAMILY:-ipv4}"
project="ipamferry-lab-${version#v}"
project="${project//./-}"
case "$netbox_image" in
  *:v4.4.10*) netbox_label="netbox-4-4-10" ;;
  *:v4.5.10*) netbox_label="netbox-4-5-10" ;;
  *:v4.6.1*) netbox_label="netbox-4-6-1" ;;
  *) netbox_label="netbox-custom" ;;
esac
result_id="${project}-${netbox_label}"
case "$primary_family" in
  ipv4) ;;
  ipv6) result_id="${result_id}-primary-ipv6" ;;
  *) printf '%s\n' 'IPAMFERRY_LAB_PRIMARY_FAMILY must be ipv4 or ipv6' >&2; exit 64 ;;
esac
runtime_dir="$root/tmp/lab/$project"
result_dir="$root/tmp/lab-results"
local_image="ipamferry-lab:${version#v}"

case "$version" in
  v1.5.2|v1.7.4|v1.8.1) ;;
  *) printf '%s\n' 'Supported phpIPAM versions: v1.5.2, v1.7.4, v1.8.1' >&2; exit 64 ;;
esac

# NetBox 4.4 images run as root and do not contain the dedicated `netbox`
# account introduced by later images.  The compatibility lab is disposable;
# select the image-compatible account here instead of weakening the normal
# sandbox definition.
case "$netbox_image" in
  *:v4.4.*)
    export IPAMFERRY_LAB_NETBOX_USER="root:root"
    export IPAMFERRY_LAB_NETBOX_TOKEN_FORMAT="legacy"
    ;;
  *)
    export IPAMFERRY_LAB_NETBOX_USER="netbox:root"
    export IPAMFERRY_LAB_NETBOX_TOKEN_FORMAT="v2"
    ;;
esac

mkdir -p "$runtime_dir"
mkdir -p "$result_dir"
# A new run must never be mistaken for a previous run of the same source and
# target combination. These are only the three deterministic, disposable
# result files for the exact matrix cell selected above.
rm -f "$result_dir/${result_id}.json" "$result_dir/${result_id}.exit" "$result_dir/${result_id}.failure.log"
compose=(docker compose --project-name "$project" --file "$root/compose.yaml" --file "$root/tests/lab/compose.yaml" --profile sandbox)

cleanup() {
  result=$?
  trap - EXIT
  if [[ "$result" -ne 0 ]]; then
    "${compose[@]}" logs --no-color phpipam-bootstrap phpipam-db sandbox-netbox 2>&1 \
      | sed -E 's/(nbt_)[^[:space:]]+/\1[REDACTED]/g; s/[A-Za-z0-9+/_=-]{48,}/[REDACTED]/g' \
      > "$result_dir/${result_id}.failure.log" || true
  fi
  "${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
  rm -rf "$runtime_dir"
  printf '%s\n' "$result" > "$result_dir/${result_id}.exit"
  exit "$result"
}
trap cleanup EXIT

export PHPIPAM_LAB_VERSION="$version"
export IPAMFERRY_LAB_PRIMARY_FAMILY="$primary_family"
export IPAMFERRY_LAB_NETBOX_IMAGE="$netbox_image"
export IPAMFERRY_IMAGE="$local_image"
export IPAMFERRY_HTTP_PORT=18081
export IPAMFERRY_HTTPS_PORT=18444
export IPAMFERRY_LAB_PHPIPAM_HTTPS_PORT=18082

docker build --target production --tag "$local_image" "$root"
"${compose[@]}" up --detach --wait phpipam-db phpipam-bootstrap phpipam-web phpipam-proxy
"${compose[@]}" exec --no-TTY phpipam-web sh -ec 'token=$(cat /run/lab-secrets/api_read_token); wget --no-check-certificate --quiet --header="phpipam-token: $token" -O - https://phpipam-proxy:8443/api/ipamferry-read/sections/ | grep -q "Lab Core"'
"${compose[@]}" exec --no-TTY phpipam-db sh -ec 'password=$(cat /run/lab-secrets/mariadb_password); mariadb-dump --skip-ssl --single-transaction --skip-comments --host=localhost --user=phpipam --password="$password" phpipam' > "$runtime_dir/phpipam.sql"
test -s "$runtime_dir/phpipam.sql"
"${compose[@]}" up --detach --wait app worker scheduler sandbox-netbox
curl --fail --insecure --silent --show-error https://localhost:18444/up >/dev/null
read_token=$("${compose[@]}" exec --no-TTY phpipam-web sh -ec 'cat /run/lab-secrets/api_read_token')
"${compose[@]}" run --rm --no-deps --entrypoint sh app -ec '
  test -r /run/ipamferry-lab-ca/root.crt
  token=$(cat /run/lab-secrets/api_read_token)
  curl --fail --silent --show-error --cacert /run/ipamferry-lab-ca/root.crt \
    --header "phpipam-token: $token" https://phpipam-proxy:8443/api/ipamferry-read/sections/ >/dev/null
'
"${compose[@]}" run --rm --no-deps \
  --entrypoint php \
  -e IPAMFERRY_APP_PATH=/app \
  -e IPAMFERRY_LAB_READ_TOKEN="$read_token" \
  -e IPAMFERRY_LAB_DUMP_PATH=/lab-input/phpipam.sql \
  -e IPAMFERRY_LAB_PRIMARY_FAMILY="$primary_family" \
  -v "$root/tests/lab:/lab:ro" \
  -v "$runtime_dir:/lab-input:ro" \
  app /lab/source-security.php
set +e
"${compose[@]}" run --rm --no-deps \
  --entrypoint php \
  -e IPAMFERRY_APP_PATH=/app \
  -e IPAMFERRY_LAB_READ_TOKEN="$read_token" \
  -e IPAMFERRY_LAB_DUMP_PATH=/lab-input/phpipam.sql \
  -v "$root/tests/lab:/lab:ro" \
  -v "$runtime_dir:/lab-input:ro" \
  app /lab/real-migration.php | tee "$result_dir/${result_id}.json"
runner_status=${PIPESTATUS[0]}
set -e
if [[ "$runner_status" -ne 0 ]]; then
  exit "$runner_status"
fi
grep -qx 'IPAMFERRY_LAB_SUCCESS' "$result_dir/${result_id}.json"
printf '%s\n' "Validated phpIPAM $version API and dump through NetBox apply, verification, and idempotency."
