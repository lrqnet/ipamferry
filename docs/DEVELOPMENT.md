# Development

> **Language:** [English](DEVELOPMENT.md) · [Português (Brasil)](pt-BR/DEVELOPMENT.md) · [Español](es/DEVELOPMENT.md)

## Local prerequisites

Use PHP 8.4, Composer, Node 22, and PostgreSQL, or use the Docker test target.

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
```

## Required checks

```bash
composer lint:check
composer types:check
php artisan test
npm run translations:check
npm run docs:check
npm run types:check
npm run lint:check
npm run build
```

Use the local Compose overlay for product verification:

```bash
docker compose --file compose.yaml --file compose.dev.yaml up -d --build --wait
docker compose ps
```

Do not use `docker compose down` on a user installation during normal verification because its volumes hold state.

## Security conventions

Use server-side validation for every input. Email addresses reject whitespace and use RFC/spoof validation. Passwords require 14–128 characters with upper/lowercase letters, a number, and a symbol. Never log plaintext passwords, API tokens, snapshots containing secrets, or raw dumps.
