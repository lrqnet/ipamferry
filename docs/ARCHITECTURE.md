# Architecture

> **Language:** [English](ARCHITECTURE.md) · [Português (Brasil)](pt-BR/ARCHITECTURE.md) · [Español](es/ARCHITECTURE.md)

## Product boundary

IpamFerry is a self-hosted Laravel application that migrates phpIPAM data to NetBox exclusively through the NetBox REST API. It never writes directly to a NetBox database and it never executes an uploaded SQL dump.

## Runtime

- Laravel 13 / PHP 8.4, Inertia, React, TypeScript, and Tailwind;
- PostgreSQL for application state, plans, checkpoints, audit records, and database queue jobs;
- FrankenPHP/Caddy as the sole LAN-exposed service;
- dedicated worker and scheduler services; and
- an optional, isolated NetBox sandbox profile.

`init` creates installation secrets without network access. PostgreSQL is private, and the application stores only sanitized source snapshots, hashes, and credential references. API tokens exist only in the request/job process that uses them.

## Migration engine

Discovery builds versioned source and destination snapshots. Mapping Studio produces canonical mapping v2 rules. Planning turns a source snapshot, destination snapshot, mapping, locale, and API versions into immutable plan v3 actions with a SHA-256 fingerprint. Apply uses persistent checkpoints, natural keys, observed state, and ETag/`If-Match` where supported to resume safely without duplication.

The ordered resource pipeline is: custom fields/tags/tenants; sites/locations; racks; manufacturers/device types/roles; devices/interfaces/MACs; providers/circuits; RIRs/ASNs; VRFs/VLANs/prefixes/IPs; then deferred assignments, primary IPs, terminations, and static NAT.

## Safety model

Existing NetBox objects are reused by default. Updates and auxiliary creations are opt-in and require plan approval. Incomplete or ambiguous input blocks approval rather than guessing. Unsupported or unsafe phpIPAM data is represented in the preservation report without secret values.

## Identity and localization

Web UI and human-readable artifacts support English, Portuguese (Brazil), and Spanish. The project locale is stored with the migration project so generated reports remain consistent. CLI output and machine-readable JSON schemas remain English.

See [ADR-0001](adr/0001-immutable-plans-api-only.md), [ADR-0002](adr/0002-mapping-v2-plan-v3.md), and [ADR-0003](adr/0003-cli-password-recovery.md).
