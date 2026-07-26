# ADR-001 — Immutable plans and API-only application

- Status: accepted
- Date: 2026-07-25

## Context

A migration could produce import files or a preloaded PostgreSQL database, but
those approaches would bypass NetBox model validation, permissions, changelog,
and internal schema compatibility.

## Decision

IpamFerry produces immutable plans bound to source, target, mapping, locale,
instance, and NetBox version fingerprints. Every create, update, and relation
is applied through the official REST API. Sandbox and production use separate
sibling plans.

## Rejected alternatives

- Writing directly to the NetBox PostgreSQL database.
- Producing a PostgreSQL dump ready to restore.
- Reusing a sandbox plan literally in production.

## Consequences

Migrations respect the NetBox application layer and every checkpoint can be
verified. The trade-off is that execution depends on a supported API and every
target requires its own discovery and plan.
