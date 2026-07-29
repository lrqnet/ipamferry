#!/bin/sh
set -eu

database_password="$(cat "$IPAM_DATABASE_PASS_FILE")"
admin_password="$(cat "$LAB_ADMIN_PASSWORD_FILE")"
read_token="$(cat "$LAB_API_READ_TOKEN_FILE")"
seed_token="$(cat "$LAB_API_SEED_TOKEN_FILE")"

until mariadb --skip-ssl --protocol=tcp --host="$IPAM_DATABASE_HOST" --user="$IPAM_DATABASE_USER" --password="$database_password" "$IPAM_DATABASE_NAME" --execute='SELECT 1' >/dev/null 2>&1; do
  sleep 1
done

if ! mariadb --skip-ssl --skip-column-names --batch --protocol=tcp --host="$IPAM_DATABASE_HOST" --user="$IPAM_DATABASE_USER" --password="$database_password" "$IPAM_DATABASE_NAME" --execute="SHOW TABLES LIKE 'sections'" | grep -qx sections; then
  mariadb --skip-ssl --protocol=tcp --host="$IPAM_DATABASE_HOST" --user="$IPAM_DATABASE_USER" --password="$database_password" "$IPAM_DATABASE_NAME" < /phpipam/db/SCHEMA.sql
fi

if ! mariadb --skip-ssl --skip-column-names --batch --protocol=tcp --host="$IPAM_DATABASE_HOST" --user="$IPAM_DATABASE_USER" --password="$database_password" "$IPAM_DATABASE_NAME" --execute="SELECT COUNT(*) FROM api WHERE app_id = 'ipamferry-read'" | grep -qx 1; then
  php /lab/seed.php "$IPAM_DATABASE_HOST" "$IPAM_DATABASE_NAME" "$IPAM_DATABASE_USER" "$database_password" "$admin_password" "$read_token" "$seed_token"
fi
