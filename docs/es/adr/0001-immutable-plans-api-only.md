# ADR-0001: Planes inmutables y aplicación solo por API

> **Idioma:** [English](../../adr/0001-immutable-plans-api-only.md) · [Português (Brasil)](../../pt-BR/adr/0001-immutable-plans-api-only.md) · [Español](0001-immutable-plans-api-only.md)

## Contexto

La migración IPAM es destructiva si origen, destino o mapeo cambian entre revisión y ejecución. La escritura directa en la base de datos NetBox omite validación, permisos y compatibilidad de API.

## Decisión

Los planes son artefactos inmutables con fingerprint vinculados a snapshots de origen y destino, mapeo, locale y versiones API. Apply y verificación usan solamente la API REST NetBox con checkpoints persistentes e idempotencia por clave natural.

## Consecuencias

Cada destino requiere su propio plan aprobado. El sistema puede detenerse y reanudarse con seguridad, pero no puede aplicar silenciosamente un plan a otro destino o inventario cambiado.
