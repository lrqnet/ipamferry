# Operations and release

> **Language:** [English](RELEASE.md) · [Português (Brasil)](pt-BR/RELEASE.md) · [Español](es/RELEASE.md)

## Install and upgrade

Install the release `compose.yaml` with `docker compose up -d --wait`. For a source checkout use the development overlay:

```bash
docker compose --file compose.yaml --file compose.dev.yaml up -d --build --wait
```

Confirm service health with `docker compose ps`. `app` is the only service exposed to the LAN. Do not expose PostgreSQL, sandbox NetBox, or internal service networks.

## Password recovery

Recovery requires Docker host administrator access and an interactive terminal:

```bash
docker compose exec -it app php artisan ipamferry:reset-password
```

When there is exactly one active owner, no email argument is needed. With no or multiple active owners, provide the exact account email:

```bash
docker compose exec -it app php artisan ipamferry:reset-password owner@example.test
```

The command never reads a password argument, environment variable, or stdin. It prompts for a hidden password and confirmation, enforces the project password policy, rotates the remember token, removes active database sessions and pending password-reset tokens, and records a minimal `cli` security event. It refuses inactive accounts. There is no web or email recovery path.

## Sandbox rehearsal

```bash
docker compose --profile sandbox up -d --wait
```

Generate a fresh plan for sandbox and another fresh sibling plan for production. Never apply a sandbox plan to a production target.
