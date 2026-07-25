# IpamFerry architecture

IpamFerry is a Laravel 13/PHP 8.4 application with Inertia 3, React 19,
TypeScript and Tailwind CSS 4, distributed through FrankenPHP/Caddy and Docker
Compose. The web panel is the primary workflow; Artisan commands reuse the same
domain services.

## Migration pipeline

```text
discover → normalize → map → plan → approve → apply → verify → bundle
```

Discovery reads the official phpIPAM API or parses a `mysqldump` strictly as
data. SQL is never executed. NetBox discovery uses the REST API, follows
pagination and reads `OPTIONS` metadata to validate required fields, choices
and value constraints for the detected version.

The automatic scope currently covers VRFs, VLAN Groups, VLANs, Prefixes, IP
Addresses and explicitly approved custom fields. Sections, devices, racks,
NAT, DNS, permissions and extension data without a safe equivalent are kept in
the preservation report.

The mapping policy is versioned JSON and cannot execute code. Existing NetBox
objects are reused by default. Updates are opt-in per object type and field.
Ambiguous matches, missing dependencies, duplicate claims, uniqueness
collisions and incompatible write schemas are blocking conflicts.

## Identity and execution safety

| NetBox object | Primary identity | Additional checked constraint |
| --- | --- | --- |
| VRF | RD; unambiguous name without RD | unique RD |
| VLAN Group | scope + name | scope + slug |
| VLAN | group + VID | group + name |
| Prefix | VRF + canonical prefix | VRF/VLAN dependencies |
| IP Address | VRF + masked address | VRF/prefix dependencies |
| Custom Field | name | compatible type and object types |

Plans are immutable and bound to source snapshot, target snapshot, mapping,
artifact locale, NetBox instance and API version. Approval applies to one exact
fingerprint. Every action has a persistent checkpoint and source-to-target
object links retain exact NetBox IDs.

Before writing, the executor checks the live destination again. Concurrent
changes are detected using `last_updated` and, when NetBox 4.6+ supplies it,
`ETag`/`If-Match`. A lost POST or PATCH response is recovered only when the
observed object exactly matches the approved payload. Repeating an applied or
verified plan returns the same execution and does not duplicate objects.

Discovery, mapping and new planning remain locked until the active execution
has been resumed and verified.

## Disposable sandbox

The optional `sandbox` profile runs pinned NetBox 4.6.1, PostgreSQL and Redis
on an internal network. Its API and databases are not published to the host.
The internal v2 token is generated automatically and kept out of application
data.

A plan is target-specific. Rehearsal and production therefore use sibling
plans:

```text
same source + same mapping + sandbox snapshot    → rehearsal plan
same source + same mapping + production snapshot → production plan
```

A sandbox plan cannot be applied to production because its target fingerprint
does not match. Apply and verification are locked to the target recorded in
the plan; moving from rehearsal to production requires a new sibling plan.

## Secrets, audit and bundles

phpIPAM and NetBox tokens exist only in request/process memory. They are not
stored in PostgreSQL, queues, events, logs, reports or ZIP bundles. Raw dumps
are private, removed after normalization and pruned after failures according to
the configured retention.

PHP uploads use a private storage-volume directory rather than the read-only
root filesystem. The container supports the default 1 GiB dump limit while
Laravel still enforces `IPAMFERRY_DUMP_MAX_BYTES`. Login is rate-limited using
an anonymized email/IP key, and locale changes become visible only after their
cookie or user preference has been persisted.

Bundles contain a versioned manifest, canonical `mapping.json` and `plan.json`,
localized HTML/JSON reports, preservation data, checkpoints and sanitized audit
events. Canonical machine keys and CLI output remain in English. IpamFerry
never produces or modifies a NetBox PostgreSQL dump.

For the detailed Portuguese reference, see
[ARQUITETURA.md](ARQUITETURA.md).
