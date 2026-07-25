#!/bin/sh
set -eu
find /app/bootstrap/cache -mindepth 1 -maxdepth 1 -type f ! -name '.gitignore' -delete
export PGPASSWORD="$(cat /run/ipamferry-recovery-secrets/postgres_admin_password)"
APP_PASSWORD="$(cat /run/ipamferry-secrets/postgres_password)"
psql -h postgres -U ipamferry_admin -d postgres -v ON_ERROR_STOP=1 \
  -v app_password="$APP_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE ipamferry LOGIN PASSWORD %L', :'app_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'ipamferry');
\gexec
SELECT 'CREATE DATABASE ipamferry OWNER ipamferry'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'ipamferry');
\gexec
SQL
set -a
. /run/ipamferry-secrets/app.env
set +a
exec su -s /bin/sh -c 'php artisan migrate --force' ipamferry
