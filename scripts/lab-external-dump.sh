#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dump_path="${1:?Provide an anonymized SQL dump path.}"
mapping_path="${2:?Provide an approved mapping JSON path.}"
project="ipamferry-external-dump-$RANDOM"
runtime_dir="$(mktemp -d "${TMPDIR:-/tmp}/ipamferry-external.XXXXXX")"
image="ipamferry-external-dump:local"
compose=(docker compose --project-name "$project" --file "$root/compose.yaml" --profile sandbox)

test -f "$dump_path"
test -f "$mapping_path"

cleanup() {
  status=$?
  trap - EXIT
  "${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
  rm -rf "$runtime_dir"
  exit "$status"
}
trap cleanup EXIT

cp "$dump_path" "$runtime_dir/source.sql"
cp "$mapping_path" "$runtime_dir/mapping.json"
chmod 0600 "$runtime_dir/source.sql" "$runtime_dir/mapping.json"

export IPAMFERRY_IMAGE="$image"
export IPAMFERRY_HTTP_PORT=18081
export IPAMFERRY_HTTPS_PORT=18444
docker build --target production --tag "$image" "$root"
"${compose[@]}" up --detach --wait init postgres database-init app sandbox-netbox
"${compose[@]}" run --rm --no-deps \
  --entrypoint php \
  -e IPAMFERRY_APP_PATH=/app \
  -e IPAMFERRY_EXTERNAL_DUMP_PATH=/external/source.sql \
  -e IPAMFERRY_EXTERNAL_MAPPING_PATH=/external/mapping.json \
  -v "$root/tests/lab:/lab:ro" \
  -v "$runtime_dir:/external:ro" \
  app /lab/external-dump.php
