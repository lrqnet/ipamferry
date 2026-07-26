# ADR-002 — Mapping v2 y plan v3

- Estado: aceptado
- Fecha: 2026-07-25

## Contexto

El mapeo v1 cubría el núcleo IPAM, pero no expresaba con seguridad políticas
por objeto, referencias portables, estados, actualizaciones y relaciones
diferidas para Tenancy, DCIM, Circuits, ASN y NAT.

## Decisión

Adoptar:

- schema de mapeo v2 con reglas identificadas y canonicalizadas;
- Mapping Studio visual sincronizado con JSON Expert;
- sugerencias deterministas que requieren aceptación;
- previews temporales producidos por el mismo planificador;
- concurrencia optimista con `mapping_revision`;
- plan v3 con acciones de objeto y relación, referencias diferidas,
  checkpoints y verificación idempotente;
- registros de recursos y planificadores separados para IPAM, Tenancy, DCIM,
  Circuits y Relations.

Las referencias usan claves naturales, nunca IDs numéricos de NetBox. Los
mapeos v1 siguen siendo verificables y solo se convierten al guardarse.

## Alternativas rechazadas

- Autosave de la política publicada.
- Expresiones PHP o JavaScript.
- Guardar IDs del sandbox en el mapping.
- Convertir parcialmente PAT, sesiones BGP o circuitos ambiguos.
- Un planificador monolítico para todos los dominios.

## Consecuencias

Un mapping puede producir planes hermanos portables, las decisiones permanecen
auditables y las relaciones diferidas pueden retomarse con seguridad. El
editor debe mantener validación, traducciones y compatibilidad de schemas, y
los objetos auxiliares requieren aprobación explícita.
