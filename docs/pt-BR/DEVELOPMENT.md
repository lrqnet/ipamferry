# Desenvolvimento

> **Idioma:** [English](../DEVELOPMENT.md) · [Português (Brasil)](DEVELOPMENT.md) · [Español](../es/DEVELOPMENT.md)

## Pré-requisitos locais

Use PHP 8.4, Composer, Node 22 e PostgreSQL, ou o target Docker de testes.

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
```

## Verificações obrigatórias

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

Use o overlay Compose local para verificar o produto:

```bash
docker compose --file compose.yaml --file compose.dev.yaml up -d --build --wait
docker compose ps
```

Não use `docker compose down` em uma instalação de usuário durante verificações comuns, porque os volumes guardam estado.

## Convenções de segurança

Use validação no servidor para toda entrada. E-mails rejeitam espaços e usam validação RFC/spoof. Senhas exigem 14–128 caracteres com maiúscula/minúscula, número e símbolo. Nunca registre senhas em texto puro, tokens de API, snapshots com segredos ou dumps brutos.
