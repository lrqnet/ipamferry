#!/bin/sh
set -eu
umask 077
mkdir -p /run/ipamferry-secrets /run/ipamferry-recovery-secrets
mkdir -p \
  /var/lib/ipamferry/storage/app/private \
  /var/lib/ipamferry/storage/framework/cache \
  /var/lib/ipamferry/storage/framework/sessions \
  /var/lib/ipamferry/storage/framework/uploads \
  /var/lib/ipamferry/storage/framework/views \
  /var/lib/ipamferry/storage/logs \
  /var/lib/ipamferry/cache \
  /var/lib/ipamferry/caddy-data \
  /var/lib/ipamferry/caddy-config \
  /var/lib/ipamferry/sandbox-postgres
chown -R 20000:20000 \
  /var/lib/ipamferry/storage \
  /var/lib/ipamferry/cache \
  /var/lib/ipamferry/caddy-data \
  /var/lib/ipamferry/caddy-config
chown -R 999:999 /var/lib/ipamferry/sandbox-postgres
write_secret() {
  target="/run/ipamferry-secrets/$1"
  bytes="$2"
  test -s "$target" || openssl rand -base64 "$bytes" | tr -d '\n' > "$target"
}

# Laravel AES-256 requires exactly 32 decoded bytes. Replace only the invalid
# key produced by early development builds, before an installation is claimed.
if [ -s /run/ipamferry-secrets/app_key ] && [ "$(base64 -d /run/ipamferry-secrets/app_key | wc -c | tr -d ' ')" != "32" ]; then
  openssl rand -base64 32 | tr -d '\n' > /run/ipamferry-secrets/app_key
fi
write_secret app_key 32
write_secret postgres_password 36
write_secret installation_token 36
write_secret internal_token 36
test -s /run/ipamferry-secrets/secret_key || openssl rand -hex 64 > /run/ipamferry-secrets/secret_key
test -s /run/ipamferry-secrets/api_token_pepper_1 || openssl rand -hex 32 > /run/ipamferry-secrets/api_token_pepper_1
test -s /run/ipamferry-secrets/superuser_api_key || openssl rand -hex 6 > /run/ipamferry-secrets/superuser_api_key
test -s /run/ipamferry-secrets/superuser_api_token || openssl rand -hex 20 > /run/ipamferry-secrets/superuser_api_token
write_secret superuser_password 36
write_secret db_password 36
write_secret redis_password 36
test -s /run/ipamferry-secrets/redis_cache_password || cp /run/ipamferry-secrets/redis_password /run/ipamferry-secrets/redis_cache_password
test -s /run/ipamferry-recovery-secrets/postgres_admin_password || openssl rand -base64 36 | tr -d '\n' > /run/ipamferry-recovery-secrets/postgres_admin_password
printf 'APP_KEY=base64:%s\n' "$(tr -d '\n' < /run/ipamferry-secrets/app_key)" > /run/ipamferry-secrets/app.env
printf 'DB_PASSWORD=%s\n' "$(tr -d '\n' < /run/ipamferry-secrets/postgres_password)" >> /run/ipamferry-secrets/app.env
chown -R 20000:20000 /run/ipamferry-secrets
chmod 0400 /run/ipamferry-secrets/*
chmod 0444 \
  /run/ipamferry-secrets/secret_key \
  /run/ipamferry-secrets/api_token_pepper_1 \
  /run/ipamferry-secrets/superuser_api_key \
  /run/ipamferry-secrets/superuser_api_token \
  /run/ipamferry-secrets/superuser_password \
  /run/ipamferry-secrets/db_password \
  /run/ipamferry-secrets/redis_password \
  /run/ipamferry-secrets/redis_cache_password
chmod 0700 /run/ipamferry-recovery-secrets
chmod 0400 /run/ipamferry-recovery-secrets/*
