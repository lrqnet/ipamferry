#!/bin/sh
set -eu

tag="$1"
release_sha="$2"
main_sha="$3"

printf '%s\n' "$tag" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+$'
test "$release_sha" = "$main_sha"
version="${tag#v}"
compose_version="$(sed -n 's#.*docker.io/lrqnet/ipamferry:\([0-9][0-9.]*\).*#\1#p' compose.yaml | head -n 1)"
test "$compose_version" = "$version"
grep -Fq "## [${version}]" CHANGELOG.md
test -z "$(git status --porcelain)"
test -f tests/lab/compatibility-manifest.json
test -f tests/lab/audit-allowlist.toml
test -f docs/VALIDATION.md
test -f docs/pt-BR/VALIDATION.md
test -f docs/es/VALIDATION.md
