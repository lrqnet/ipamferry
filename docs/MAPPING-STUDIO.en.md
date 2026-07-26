# Mapping Studio

Mapping Studio is the visual step between discovery and the official plan. It
edits the canonical mapping policy without executable code and lets operators
review migration impact before producing an approvable plan.

## Workflow

1. Rediscover the source and target whenever their data changes.
2. Open **Mapping Studio** from the project.
3. Review object policies and accept only the desired suggestions.
4. Resolve references, required fields, and relations.
5. Run previews until no blocking conflicts remain.
6. Save the policy explicitly.
7. Return to the project and generate the official plan.
8. After verifying a rehearsal, refresh the NetBox snapshot before generating
   a sibling plan; the backend rejects planning against the old snapshot.

Preview runs the same planner as an official plan, but is temporary,
non-approvable, and non-applicable. It is bound to the source fingerprint,
target fingerprint, and mapping revision.

## Sections

- **Overview** shows the revision, schema, coverage, and deterministic
  suggestions.
- **Objects** selects `migrate`, `ignore`, or `preserve` for each type.
- **References** uses portable natural keys instead of NetBox numeric IDs.
- **Fields** supports copy, ignore, fixed value, concatenate, normalize, and
  lookup operations.
- **Status/updates** maps `OPTIONS` choices and authorizes individual fields
  for `PATCH`.
- **Relations** classifies Locations, defines category defaults and per-device
  prerequisite overrides, and approves contacts, ASN/RIR, circuits, primary
  IP, and static 1:1 NAT.
- **Preview** reports estimated actions, preservation, warnings, and conflicts.
- **JSON Expert** edits the same canonical document with path validation.

Suggestions are never published automatically. The editor keeps local
undo/redo history, warns before leaving with unsaved changes, and uses
`mapping_revision` optimistic concurrency.

## Sanitized catalog

The browser receives only summarized field names, inferred types, fill rates,
bounded cardinality, up to five truncated examples, decision-specific identity
summaries, bounded relationship hints containing only the source IDs required
for category, location, rack, device, interface, provider, and type, and NetBox
write choices. Complete snapshots, tokens, sensitive fields, and secret-like
values are never sent to the UI. The versioned catalog is bound to source and
target fingerprints.

## Mapping schema v2

Canonical JSON keys remain in English:

| Section              | Responsibility                                  |
| -------------------- | ----------------------------------------------- |
| `object_policies`    | Migrate, ignore, or preserve each object type   |
| `reference_rules`    | Resolve references by natural key               |
| `status_rules`       | Convert status to valid NetBox choices          |
| `update_rules`       | Authorize existing fields for `PATCH`           |
| `field_rules`        | Transform values without executable code        |
| `relation_rules`     | Configure deferred relations and dependencies   |
| `preservation_rules` | Explain handling for data without an equivalent |

Stable rule IDs and canonical ordering ensure that visual reordering does not
change a fingerprint. V1 mappings remain valid and are upgraded only when an
operator explicitly saves the conversion.

## Expanded equivalences

| phpIPAM                          | NetBox or behavior                                                                                                               |
| -------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Customers                        | Tenant; Contact only with an approved Contact Role                                                                               |
| Sections and IP Tags             | Opt-in Tags and status rules                                                                                                     |
| Locations                        | Site or Location under a Site                                                                                                    |
| Racks                            | Rack with resolved Site/Location                                                                                                 |
| Device Types                     | Device Role, never a physical model                                                                                              |
| Devices                          | Device with mandatory Site, Role, and Device Type                                                                                |
| Ports and MACs                   | Interface and MAC Address when valid                                                                                             |
| Circuits                         | Provider, Circuit Type, Circuit, and safe terminations; types require a dump because the official API exposes no such collection |
| BGP                              | ASN; sessions remain preserved                                                                                                   |
| NAT                              | Confirmed static 1:1 IP-to-IP relation only                                                                                      |
| Hostnames                        | IP Address `dns_name`                                                                                                            |
| Authoritative DNS and extensions | Preservation or approved custom field                                                                                            |

Missing auxiliary objects are proposed, but Manufacturer, Device Type, Site,
Contact Role, RIR, and similar records enter a plan only after approval.

## Safety limits

- A Device without Site, Role, or Device Type blocks planning.
- Device Type requires Manufacturer; Rack and Location require Site.
- A device IP without a port remains unassigned and produces a warning.
- An invalid or portless MAC is preserved with a reason; repeated valid values
  on the same port do not create duplicate interfaces or MAC addresses.
- Primary IP requires one unambiguous migrated and assigned IP.
- PAT, port-based NAT, many-to-many NAT, and BGP sessions are never partially
  converted.
- Circuit terminations require an unambiguous location; cables are not
  invented.
- Unknown extension tables stay outside the whitelist. Exclusion is reported
  without preserving potentially secret values.

## Plan v3 and ordering

Plan schema v3 separates object and relation actions and retains deferred
references, checkpoints, and idempotent verification:

```text
custom fields/tags/tenants
→ sites/locations
→ racks
→ manufacturers/device types/roles
→ devices/interfaces/MACs
→ providers/circuits
→ RIRs/ASNs
→ VRFs/VLANs/prefixes/IPs
→ assignments/primary IP/NAT
```

Application remains REST API-only with locks, ETag support when available,
checkpoints, and persistent links. Verification canonicalizes differences
between REST write fields and representations, including
`termination_id`/`termination`, object lists, and integer values serialized as
decimals, without accepting semantically different values.

## Compatibility

- phpIPAM 1.5 through 1.8;
- NetBox 4.4 through 4.6;
- sandbox pinned to NetBox 4.6.1.

The phpIPAM API adapter uses only officially exposed controllers. Dump-only
modules are explicitly marked in coverage.

See [Architecture](ARCHITECTURE.en.md) and
[ADR-002](adr/0002-mapping-v2-plan-v3.en.md).
