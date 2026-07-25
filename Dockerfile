# syntax=docker/dockerfile:1.7
FROM composer:2 AS dependencies
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts

FROM composer:2 AS development-dependencies
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-progress --no-scripts --prefer-dist
COPY . .
RUN composer dump-autoload --classmap-authoritative --no-scripts

FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY resources resources
COPY public public
COPY vite.config.ts tsconfig.json ./
RUN npm run build

FROM dunglas/frankenphp:1-php8.4-bookworm AS production
RUN install-php-extensions intl opcache pcntl pdo_pgsql pdo_sqlite zip \
    && apt-get update && apt-get install -y --no-install-recommends ca-certificates curl postgresql-client util-linux && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid 20000 ipamferry \
    && useradd --uid 20000 --gid 20000 --home-dir /app --no-create-home --shell /usr/sbin/nologin ipamferry
WORKDIR /app
COPY --from=dependencies /app /app
COPY --from=frontend /app/public/build /app/public/build
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/ipamferry.ini
COPY docker/entrypoint.sh docker/init-secrets.sh docker/database-initialize.sh docker/worker.sh docker/scheduler.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/*.sh \
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p /app/storage/app/private /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/uploads /app/storage/framework/views /app/storage/logs /app/bootstrap/cache \
    && chown -R 20000:20000 /app/storage /app/bootstrap/cache
ARG IPAMFERRY_VERSION=dev
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr SESSION_DRIVER=database CACHE_STORE=database QUEUE_CONNECTION=database OCTANE_SERVER=frankenphp IPAMFERRY_VERSION=${IPAMFERRY_VERSION}
USER 20000:20000
EXPOSE 8080 8443
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

FROM production AS test
COPY --chown=20000:20000 --from=development-dependencies /app/vendor /app/vendor
ENV APP_ENV=testing \
    APP_DEBUG=true \
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    DB_CONNECTION=sqlite \
    DB_DATABASE=:memory: \
    CACHE_STORE=array \
    QUEUE_CONNECTION=sync \
    SESSION_DRIVER=array \
    MAIL_MAILER=array
ENTRYPOINT []
CMD ["php", "artisan", "test"]

FROM production AS runtime
