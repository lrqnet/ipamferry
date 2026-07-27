<p align="center">
  <img src="public/brand/ipamferry-shield-512.png" width="144" alt="IpamFerry shield: a ferry connecting network nodes" />
</p>

<h1 align="center">IpamFerry</h1>

<p align="center">Auditable phpIPAM-to-NetBox migrations.</p>

> **Language:** [English](README.md) · [Português (Brasil)](docs/pt-BR/README.md) · [Español](docs/es/README.md)

IpamFerry is a self-hosted workbench for planning and applying phpIPAM migrations to NetBox. It reads the official phpIPAM API or a `mysqldump` SQL file, produces a reviewable plan, applies only through the official NetBox API, and exports an audit bundle.

> [!WARNING]
> IPAM datasets and API tokens are sensitive. Restrict access to an administrative network and always use HTTPS.

## Install

Download `compose.yaml` from a release, then run:

```bash
docker compose up -d --wait
docker compose ps
docker compose exec app php artisan ipamferry:installation-token
```

Open `https://YOUR_SERVER`, use the token to create the first owner, and finish setup. Compose generates internal keys and passwords in Docker volumes; no operational `.env` file is required.

## Recover a local password

Password recovery is intentionally performed by a Docker host administrator, not through email:

```bash
docker compose exec -it app php artisan ipamferry:reset-password
```

The command must run in an interactive terminal. When exactly one active owner exists, it selects that account; otherwise provide the exact email address:

```bash
docker compose exec -it app php artisan ipamferry:reset-password owner@example.test
```

It asks for confirmation and a hidden password twice, applies the same 14–128-character policy used during setup, invalidates active database sessions and pending reset tokens, and rotates the remember token. The password is never accepted as an argument, environment variable, or standard input. Host/Docker administrator access is therefore the recovery authority.

## Migration workflow

1. Create a project and choose **phpIPAM API** or **mysqldump SQL**.
2. Discover source and destination. Tokens remain only in request/job memory.
3. Use **Mapping Studio** to review mappings and run a non-applicable preview.
4. Generate a destination-specific plan and resolve all conflicts.
5. An `owner` or `administrator` approves the exact fingerprint.
6. Apply through the NetBox REST API. Persistent checkpoints support safe resume.
7. Verify by identifiers and natural keys, then download the audit bundle.

Automatic migration covers safe NetBox core equivalents including tenants, tags, sites, locations, racks, manufacturers, device roles/types, devices, interfaces, MACs, providers, circuits, RIRs, ASNs, VRFs, VLAN groups/VLANs, prefixes, IP addresses, and approved custom fields. Primary IP, circuit terminations, and static 1:1 NAT are applied only after unambiguous matching and approval.

PAT, BGP sessions, authoritative DNS, permissions, and extensions without a safe equivalent are preserved and explained; they are never partially converted or silently discarded.

For a rehearsal environment:

```bash
docker compose --profile sandbox up -d --wait
```

A sandbox plan must never be reused in production. Rediscover the production NetBox and generate a sibling plan for it.

## Development

PHP 8.4, Composer, and Node 22 are required outside Docker:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
php artisan test
```

CLI output and re-executable JSON keys are always English. See the [documentation index](docs/README.md) for architecture, Mapping Studio, operations, releases, and ADRs.

## Release validation

The disposable real-source laboratory, version matrix, and coverage rules are documented in [Release validation](docs/VALIDATION.md).

## License

IpamFerry is licensed under [AGPL-3.0-only](LICENSE).
