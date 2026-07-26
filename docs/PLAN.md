# Historical plan

> **Language:** [English](PLAN.md) · [Português (Brasil)](pt-BR/PLAN.md) · [Español](es/PLAN.md)

This document records the product direction adopted for IpamFerry 0.2. It is not an executable migration plan; current behavior is defined by the application, tests, [architecture](ARCHITECTURE.md), and ADRs.

## Direction

The panel is the primary interface and Artisan commands are the automation interface. Migration is always API-based against NetBox. phpIPAM input is either its official API or a read-only parsed `mysqldump`; no dump is executed.

## Delivery scope

- secure Compose installation with isolated PostgreSQL and optional sandbox;
- source/destination discovery, Mapping Studio, preview, immutable plans, checkpointed apply, verification, and audit bundle;
- safe NetBox core resource coverage and preservation reporting for unsupported data;
- English-first public documentation and complete Portuguese (Brazil) and Spanish mirrors; and
- host-administrator CLI password recovery with no email dependency.

## Explicit exclusions

Plugins, authoritative DNS conversion, BGP-session conversion, PAT, arbitrary executable mapping expressions, direct NetBox database writes, and automatic acceptance of suggestions remain out of scope.
