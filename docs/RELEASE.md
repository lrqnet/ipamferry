# Operations and release

> **Language:** [English](RELEASE.md) · [Português (Brasil)](pt-BR/RELEASE.md) · [Español](es/RELEASE.md)

## Install and upgrade

Install the release `compose.yaml` with `docker compose up -d --wait`. For a source checkout use the development overlay:

```bash
docker compose --file compose.yaml --file compose.dev.yaml up -d --build --wait
```

Confirm service health with `docker compose ps`. `app` is the only service exposed to the LAN. Do not expose PostgreSQL, sandbox NetBox, or internal service networks.

## In-panel updates

The footer shows the installed version on every page. Once every 24 hours, IpamFerry checks the public GitHub API for the newest stable release; no installation identifier, migration data, credentials, or telemetry is sent. An owner can also select **Check for updates**.

Only an owner can confirm an update. The updater downloads the release `compose.yaml`, validates its published SHA-256 checksum, and accepts only an IpamFerry image pinned by digest. It refuses pre-releases, downgrades, concurrent updates, and updates while discovery, planning, apply, or verification is running. The application restarts briefly after the update.

The `updater` service has Docker socket access so it can recreate the IpamFerry application services; this is equivalent to Docker-host administrator authority. It has no network access and is not exposed to the LAN. Do not enable `IPAMFERRY_UPDATES_ENABLED` on a host where owners must not have this operational capability. If the replacement app does not become healthy, automatic rollback is intentionally not attempted because database migrations may be irreversible; inspect `docker compose logs updater` and follow the release notes.

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
