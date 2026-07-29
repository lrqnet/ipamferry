# Mapping Studio

> **Language:** [English](MAPPING-STUDIO.md) · [Português (Brasil)](pt-BR/MAPPING-STUDIO.md) · [Español](es/MAPPING-STUDIO.md)

Mapping Studio is the explicit policy editor between discovery and formal planning. Readers can inspect it; operators, administrators, and owners can save changes when no execution lock exists.

## Workspace

The studio provides Overview, Objects, References, Fields, Status/updates, Relations, Preview, and JSON Expert. The visual editor shows only a sanitized catalog: inferred type, fill ratio, bounded cardinality, and up to five truncated examples. Full snapshots, secrets, and credentials never reach the browser.

Suggestions are deterministic and based on names, slugs, types, and natural keys. They are never persisted until the operator accepts and explicitly saves them. Preview runs the same planner asynchronously but cannot be approved or applied.

## Mapping v2

Canonical English JSON stores stable, ordered rule IDs for `object_policies`, `reference_rules`, `status_rules`, `update_rules`, `field_rules`, `relation_rules`, and `preservation_rules`. References use natural keys, never destination numeric IDs. Mapping v1 is read compatibly and upgrades only when saved.

The JSON Expert uses CodeMirror, JSON Pointer validation, apply/discard controls, local undo/redo, unsaved-change warning, and `mapping_revision` optimistic concurrency.

## Device and relation rules

Devices require a Site, Device Role, Manufacturer, and Device Type. Racks and locations require a Site. Interfaces require a valid type; IP-to-interface attachment requires a port. Primary IP, circuit termination, and static 1:1 NAT require unambiguous migrated identities. PAT, port NAT, many-to-many NAT, BGP sessions, and invented cabling are preserved instead of partially transformed.
