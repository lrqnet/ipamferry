# Desarrollo

> **Idioma:** [English](../DEVELOPMENT.md) · [Português (Brasil)](../pt-BR/DEVELOPMENT.md) · [Español](DEVELOPMENT.md)

## Requisitos locales

Use PHP 8.4, Composer, Node 22 y PostgreSQL, o el target Docker de pruebas.

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
```

## Verificaciones obligatorias

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

Use el overlay Compose local para verificar el producto:

```bash
docker compose --file compose.yaml --file compose.dev.yaml up -d --build --wait
docker compose ps
```

No use `docker compose down` en una instalación de usuario durante verificaciones normales, porque los volúmenes contienen estado.

## Convenciones de seguridad

Use validación del servidor para toda entrada. Los correos rechazan espacios y usan validación RFC/spoof. Las contraseñas exigen 14–128 caracteres con mayúscula/minúscula, número y símbolo. Nunca registre contraseñas en texto plano, tokens API, snapshots con secretos o volcados sin procesar.
