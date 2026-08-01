# Changelog

> **Language:** [English](CHANGELOG.md) · [Português (Brasil)](docs/pt-BR/CHANGELOG.md) · [Español](docs/es/CHANGELOG.md)

All notable changes to this project are documented in this file.

## [0.3.0] - 2026-08-01

### Added

- Global responsive footer with project repository, author, GitHub Sponsors link, installed version, and owner-only update controls.
- Daily, privacy-preserving stable-release checks and a secure in-panel update workflow using verified checksums and digest-pinned Compose artifacts.
- Dedicated least-privilege updater service, persistent update status, concurrent-update protection, migration-operation blocking, and health-check failure reporting.

### Fixed

- Header branding and language selector now use the full available width instead of collapsing together on narrow layouts.
- The updater uses a dedicated private exchange volume so it works correctly with non-root Laravel containers on Docker Desktop and Linux hosts.

## [0.2.0] - 2026-07-28

### Added

- Visual Mapping Studio with deterministic suggestions, sanitized catalog, asynchronous preview, JSON Expert, undo/redo, and optimistic concurrency.
- Mapping schema v2 and plan v3 with object/relation actions, deferred references, and stable canonicalization.
- Safe NetBox core migration coverage for tenancy, DCIM, circuits, and IPAM resources.
- Interactive host-administrator password recovery command with session invalidation and security-event audit record.
- English-first public documentation with Portuguese (Brazil) and Spanish mirrors.

### Changed

- Fortify email password-reset routes are disabled; recovery has no mail dependency.
- Missing auxiliary objects require explicit approval before entering a plan.

### Fixed

- Mapping Studio shows only the global interface language selector; report and bundle language remains a project setting.

## [0.1.0] - 2026-07-24

### Added

- Laravel/Inertia foundation for the phpIPAM-to-NetBox migration workbench.
- Secure Compose stack, API/dump discovery, planning, audit bundles, and NetBox sandbox.
