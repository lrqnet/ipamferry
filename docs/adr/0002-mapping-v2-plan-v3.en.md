# ADR-002 — Mapping v2 and plan v3

- Status: accepted
- Date: 2026-07-25

## Context

Mapping v1 covered the IPAM core but could not safely express per-object
policies, portable references, statuses, updates, and deferred relations for
Tenancy, DCIM, Circuits, ASN, and NAT.

## Decision

Adopt:

- mapping schema v2 with identified and canonicalized rules;
- a visual Mapping Studio synchronized with JSON Expert;
- deterministic suggestions requiring acceptance;
- temporary previews produced by the same planner;
- optimistic concurrency through `mapping_revision`;
- plan v3 with object and relation actions, deferred references, checkpoints,
  and idempotent verification;
- resource registries and separate IPAM, Tenancy, DCIM, Circuits, and Relations
  planners.

References use natural keys, never target-specific NetBox numeric IDs. V1
mappings remain verifiable and are converted only when explicitly saved.

## Rejected alternatives

- Autosaving the published policy.
- PHP or JavaScript transformation expressions.
- Persisting sandbox IDs in mappings.
- Partial conversion of PAT, BGP sessions, or ambiguous circuits.
- One monolithic planner for every domain.

## Consequences

A mapping can produce portable sibling plans, decisions remain auditable, and
deferred relations can be resumed safely. The editor must maintain validation,
translations, and schema compatibility, while auxiliary objects require
explicit approval.
