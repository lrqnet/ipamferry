# ADR-0002: Mapping v2 y plan v3

> **Idioma:** [English](../../adr/0002-mapping-v2-plan-v3.md) · [Português (Brasil)](../../pt-BR/adr/0002-mapping-v2-plan-v3.md) · [Español](0002-mapping-v2-plan-v3.md)

## Contexto

Un editor JSON aislado era difícil de revisar y el planificador original no representaba relaciones diferidas seguras entre DCIM, circuits e IPAM.

## Decisión

Adoptar Mapping Studio con editor visual y JSON Expert sincronizado. Mapping v2 usa IDs de reglas canónicos y estables y claves naturales. Plan v3 separa acciones de objeto y relación, incluye referencias diferidas y checkpoints y preserva la verificación histórica v1/v2.

## Consecuencias

Los operadores obtienen un flujo de políticas revisable mientras los schemas legibles por máquina permanecen en inglés y estables. Sugerencias, updates y creación de recursos auxiliares requieren aprobación explícita.
