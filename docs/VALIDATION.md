# Release validation

> **Language:** [English](VALIDATION.md) · [Português (Brasil)](pt-BR/VALIDATION.md) · [Español](es/VALIDATION.md)

IpamFerry maintains a disposable, local laboratory for validating a real phpIPAM source, a real `mysqldump`, and the NetBox REST API path. It never uses an operator installation, its Docker volumes, or its credentials.

## Run locally

```bash
./scripts/lab-test.sh v1.8.1
```

The lab creates short-lived credentials in Docker volumes, exposes only loopback test interfaces, validates the phpIPAM read-only API, creates a `mysqldump --single-transaction` in the ignored `tmp/lab/` directory, and removes all lab volumes and generated files when it finishes.

## Compatibility matrix

The immutable source image tags and digests are in [`tests/lab/compatibility-manifest.json`](../tests/lab/compatibility-manifest.json). Release validation requires the following isolated combinations:

| phpIPAM | NetBox | Level |
| --- | --- | --- |
| 1.5.2 | 4.6.1 | Deep |
| 1.7.4 | 4.6.1 | Deep |
| 1.8.1 | 4.6.1 | Deep |
| 1.8.1 | 4.4.10 | Smoke |
| 1.8.1 | 4.5.10 | Smoke |

The deep journey covers source discovery, dump parsing, Mapping Studio, preview, approved API application, resume, idempotency, verification, and localized audit bundles. The smoke journey verifies source discovery, planning, and safe API compatibility.

## Coverage and exclusions

[`tests/lab/coverage-manifest.json`](../tests/lab/coverage-manifest.json) classifies every known laboratory table as `migrated`, `preserved`, `sensitive_excluded`, `unsupported`, or `not_available_in_version`. Credentials, users, sessions, vault data, API applications, and scan-agent configuration are never included in plans, bundles, reports, CI artifacts, or public documentation.

[`tests/lab/scenario-matrix.json`](../tests/lab/scenario-matrix.json) defines the data-level acceptance cases: CIDR boundaries, IPv6 canonicalization, VRF/VLAN identities, hierarchy, text limits, custom fields, tenancy, DCIM, circuits, NAT, target drift, recovery, and sanitization. Each case declares whether it must migrate, be preserved, be blocked for review, or be rejected safely.

Raw dumps, databases, generated bundles, Playwright traces, screenshots, and unsanitized container logs are intentionally not retained as CI artifacts.

## Local validation status

The matrix is an active release gate: a scenario is complete only when its executable evidence has passed for every required compatibility combination. On 2026-07-26, all five listed compatibility combinations passed using both the read-only phpIPAM API and a real `mysqldump`. Each run applied, verified, and safely re-applied 76 core actions and 109 approved extended actions without duplication. The NetBox 4.6.1 deep journey also resumed the same real execution through 76 persisted one-action checkpoints. The corpus creates and reads back approved NetBox custom fields and their choice sets, preserves unsafe NAT variants and invalid DNS names, and confirms that neither is sent to NetBox.
