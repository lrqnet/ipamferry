#!/bin/sh
set -eu
test -f /run/ipamferry-secrets/app.env && set -a && . /run/ipamferry-secrets/app.env && set +a
exec "$@"
