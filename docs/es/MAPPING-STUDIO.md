# Mapping Studio

> **Idioma:** [English](../MAPPING-STUDIO.md) · [Português (Brasil)](../pt-BR/MAPPING-STUDIO.md) · [Español](MAPPING-STUDIO.md)

Mapping Studio es el editor explícito de políticas entre discovery y planificación formal. Los readers pueden consultar; operators, administrators y owners pueden guardar cambios cuando no existe bloqueo de ejecución.

## Espacio de trabajo

El studio incluye Overview, Objects, References, Fields, Status/updates, Relations, Preview y JSON Expert. El editor visual muestra solo un catálogo sanitizado: tipo inferido, porcentaje de llenado, cardinalidad limitada y hasta cinco ejemplos truncados. Snapshots completos, secretos y credenciales nunca llegan al navegador.

Las sugerencias son deterministas, basadas en nombres, slugs, tipos y claves naturales. Nunca persisten hasta que el operador las acepta y guarda explícitamente. Preview ejecuta el mismo planificador de forma asíncrona, pero no puede aprobarse ni aplicarse.

## Mapping v2

El JSON canónico en inglés guarda IDs de regla estables y ordenados para `object_policies`, `reference_rules`, `status_rules`, `update_rules`, `field_rules`, `relation_rules` y `preservation_rules`. Las referencias usan claves naturales, nunca IDs numéricos de destino. Mapping v1 sigue siendo legible y solo se actualiza al guardar.

JSON Expert usa CodeMirror, validación JSON Pointer, controles aplicar/descartar, undo/redo local, aviso de cambios no guardados y concurrencia optimista mediante `mapping_revision`.

## Reglas de devices y relaciones

Devices requieren Site, Device Role, Manufacturer y Device Type. Racks y locations requieren Site. Interfaces requieren tipo válido; la asociación IP-interfaz exige puerto. Primary IP, terminaciones de circuito y NAT estático 1:1 requieren identidades migradas inequívocas. PAT, NAT con puertos, NAT muchos-a-muchos, sesiones BGP y cableado inventado se preservan en vez de transformarse parcialmente.
