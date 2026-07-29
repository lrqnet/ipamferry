# ADR-0002: Mapping v2 and plan v3

> **Language:** [English](0002-mapping-v2-plan-v3.md) · [Português (Brasil)](../pt-BR/adr/0002-mapping-v2-plan-v3.md) · [Español](../es/adr/0002-mapping-v2-plan-v3.md)

## Context

A JSON-only mapping editor was difficult to review, and the original planner could not represent safe deferred relationships across DCIM, circuits, and IPAM.

## Decision

Adopt Mapping Studio with a visual editor and synchronized JSON Expert. Mapping v2 uses canonical, stable rule IDs and natural keys. Plan v3 separates object and relationship actions, includes deferred references and checkpoints, and preserves v1/v2 historical verification.

## Consequences

Operators get a reviewable policy workflow while machine-readable schemas remain English and stable. Suggestions, updates, and auxiliary resource creation require explicit approval.
