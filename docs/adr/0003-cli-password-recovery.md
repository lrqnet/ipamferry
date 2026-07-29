# ADR-0003: Command-only password recovery

> **Language:** [English](0003-cli-password-recovery.md) · [Português (Brasil)](../pt-BR/adr/0003-cli-password-recovery.md) · [Español](../es/adr/0003-cli-password-recovery.md)

## Context

IpamFerry is commonly installed as a private, single-owner appliance. It has no configured outbound mail dependency, and an incomplete public reset flow would add an unnecessary attack surface.

## Decision

Password recovery is an interactive CLI operation available only to a Docker host administrator:

```bash
docker compose exec -it app php artisan ipamferry:reset-password
```

The command uses the setup password policy, never accepts a password through arguments, environment variables, or stdin, requires confirmation, and runs atomic credential/session invalidation. Fortify email-reset routes are disabled. A successful reset creates only a minimal security event with user ID, event kind, timestamp, and `cli` origin.

## Consequences

Recovery authority is explicit: an administrator must have host/Docker access. Users cannot self-reset through email. Existing password-reset-token storage remains for compatibility but is cleared for the target during a successful recovery.
