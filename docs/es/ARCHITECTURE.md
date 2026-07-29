# Arquitectura

> **Idioma:** [English](../ARCHITECTURE.md) · [Português (Brasil)](../pt-BR/ARCHITECTURE.md) · [Español](ARCHITECTURE.md)

## Límite del producto

IpamFerry es una aplicación Laravel autoalojada que migra datos de phpIPAM a NetBox exclusivamente mediante la API REST de NetBox. Nunca escribe directamente en una base de datos de NetBox ni ejecuta un volcado SQL cargado.

## Runtime

- Laravel 13 / PHP 8.4, Inertia, React, TypeScript y Tailwind;
- PostgreSQL para estado de aplicación, planes, checkpoints, auditoría y jobs;
- FrankenPHP/Caddy como único servicio expuesto a la LAN;
- servicios dedicados de worker y scheduler; y
- perfil opcional y aislado de sandbox NetBox.

`init` crea secretos de instalación sin acceso de red. PostgreSQL es privado y la aplicación guarda solo snapshots sanitizados, hashes y referencias de credenciales. Los tokens API existen únicamente en el proceso de la solicitud/job que los usa.

## Motor de migración

Discovery crea snapshots versionados del origen y destino. Mapping Studio produce reglas canónicas mapping v2. Planning transforma snapshot de origen, snapshot de destino, mapeo, locale y versiones API en acciones inmutables plan v3 con fingerprint SHA-256. Apply usa checkpoints persistentes, claves naturales, estado observado y ETag/`If-Match` cuando existe para reanudar sin duplicación.

El orden de recursos es: custom fields/tags/tenants; sites/locations; racks; manufacturers/device types/roles; devices/interfaces/MACs; providers/circuits; RIRs/ASNs; VRFs/VLANs/prefixes/IPs; después asignaciones diferidas, primary IPs, terminaciones y NAT estático.

## Modelo de seguridad

Los objetos NetBox existentes se reutilizan por defecto. Las actualizaciones y creaciones auxiliares son opt-in y exigen aprobación del plan. Datos incompletos o ambiguos bloquean la aprobación en lugar de adivinar. Los datos phpIPAM sin soporte o inseguros aparecen en el informe de preservación sin valores secretos.

## Identidad y localización

La interfaz web y los artefactos legibles admiten inglés, portugués de Brasil y español. El locale del proyecto se guarda con el proyecto de migración para que los informes permanezcan consistentes. La salida CLI y los schemas JSON legibles por máquina permanecen en inglés.

Consulte [ADR-0001](adr/0001-immutable-plans-api-only.md), [ADR-0002](adr/0002-mapping-v2-plan-v3.md) y [ADR-0003](adr/0003-cli-password-recovery.md).
