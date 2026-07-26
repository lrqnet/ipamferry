# ADR-001 — Planes inmutables y aplicación solo por API

- Estado: aceptado
- Fecha: 2026-07-25

## Contexto

Una migración podría producir archivos importables o una base PostgreSQL
precargada, pero esos métodos evitarían las validaciones de modelos, permisos,
changelog y compatibilidad interna de NetBox.

## Decisión

IpamFerry genera planes inmutables vinculados a las huellas del origen,
destino, mapeo, locale, instancia y versión de NetBox. Toda creación,
actualización y relación se aplica por la API REST oficial. Sandbox y producción
usan planes hermanos separados.

## Alternativas rechazadas

- Escribir directamente en PostgreSQL de NetBox.
- Generar un dump PostgreSQL listo para restaurar.
- Reutilizar literalmente un plan del sandbox en producción.

## Consecuencias

Las migraciones respetan la capa de aplicación de NetBox y permiten verificar
cada checkpoint. Como contrapartida, la ejecución depende de una API soportada
y cada destino necesita su propio descubrimiento y plan.
