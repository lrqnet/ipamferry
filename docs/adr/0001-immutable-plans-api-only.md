# ADR-0001: Immutable plans and API-only application

> **Language:** [English](0001-immutable-plans-api-only.md) · [Português (Brasil)](../pt-BR/adr/0001-immutable-plans-api-only.md) · [Español](../es/adr/0001-immutable-plans-api-only.md)

## Context

IPAM migration is destructive if source, target, or mapping changes between review and execution. Direct NetBox database writes bypass validation, permissions, and API compatibility.

## Decision

Plans are immutable, fingerprinted artifacts bound to source snapshot, destination snapshot, mapping, locale, and API versions. Apply and verification use only the NetBox REST API with persistent checkpoints and natural-key idempotency.

## Consequences

Every target requires its own approved plan. The system can stop safely and resume, but it cannot silently apply a plan to a different target or changed inventory.
