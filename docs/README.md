# Documentation

> **Language:** [English](README.md) · [Português (Brasil)](pt-BR/DOCUMENTATION.md) · [Español](es/DOCUMENTATION.md)

IpamFerry documentation is English-first. Complete translations are available in Portuguese (Brazil) and Spanish.

- [Architecture](ARCHITECTURE.md)
- [Mapping Studio](MAPPING-STUDIO.md)
- [Development](DEVELOPMENT.md)
- [Operations and release](RELEASE.md)
- [Release validation](VALIDATION.md)
- [Historical plan](PLAN.md)
- [Architecture decisions](adr/)

## Password recovery

Use the host-administrator-only interactive command:

```bash
docker compose exec -it app php artisan ipamferry:reset-password
```

See [Operations and release](RELEASE.md#password-recovery) for security properties and multi-user behavior.
