# Mapping Studio

Mapping Studio es la etapa visual entre el descubrimiento y el plan oficial.
Edita la política canónica sin código ejecutable y permite revisar el impacto
antes de producir un plan aprobable.

## Flujo

1. Vuelve a descubrir el origen y el destino cuando cambien los datos.
2. Abre **Mapping Studio** desde el proyecto.
3. Revisa las políticas y acepta solo las sugerencias deseadas.
4. Resuelve referencias, campos obligatorios y relaciones.
5. Ejecuta previews hasta eliminar los conflictos bloqueantes.
6. Guarda la política de forma explícita.
7. Regresa al proyecto y genera el plan oficial.
8. Después de verificar un ensayo, actualiza el snapshot de NetBox antes de
   generar un plan hermano; el backend rechaza planificar con el snapshot
   anterior.

El preview usa el mismo planificador, pero es temporal, no aprobable y no
aplicable. Queda vinculado a las huellas del origen y del destino y a la
revisión del mapeo.

El selector del encabezado cambia solo el idioma de la interfaz. Los informes,
archivos de preservación y el bundle auditable usan una configuración separada
y claramente identificada en la página del proyecto, fuera de Mapping Studio.

## Secciones

- **Resumen** muestra revisión, schema, cobertura y sugerencias deterministas.
- **Objetos** selecciona `migrate`, `ignore` o `preserve`.
- **Referencias** usa claves naturales portables, nunca IDs de NetBox.
- **Campos** permite copiar, ignorar, fijar, concatenar, normalizar y lookup.
- **Estados/updates** convierte choices de `OPTIONS` y autoriza campos para
  `PATCH`.
- **Relaciones** clasifica Locations, define valores por categoría y
  excepciones individuales de Devices y aprueba contactos, ASN/RIR, circuitos,
  primary IP y NAT 1:1.
- **Preview** muestra acciones estimadas, preservación, warnings y conflictos.
- **JSON Expert** edita el mismo documento con validación por ruta.

Las sugerencias nunca se publican automáticamente. El editor mantiene undo/redo
local, alerta al salir con cambios pendientes y usa `mapping_revision` para
evitar sobrescrituras silenciosas.

## Catálogo saneado

El navegador recibe solo nombres y tipos inferidos de campos, porcentaje de
llenado, cardinalidad limitada, hasta cinco ejemplos truncados, identidades
resumidas, hints de relación limitados a los IDs de origen necesarios para
categoría, ubicación, rack, device, interfaz, provider y tipo, y choices de
escritura de NetBox. Nunca recibe snapshots completos, tokens, campos sensibles
ni valores similares a secretos. El catálogo versionado está ligado a las
huellas de origen y destino.

## Schema de mapeo v2

Las claves del JSON canónico permanecen en inglés:

| Sección              | Responsabilidad                                  |
| -------------------- | ------------------------------------------------ |
| `object_policies`    | Migrar, ignorar o preservar cada tipo            |
| `reference_rules`    | Resolver referencias por clave natural           |
| `status_rules`       | Convertir estados a choices válidas              |
| `update_rules`       | Autorizar campos existentes para `PATCH`         |
| `field_rules`        | Transformar valores sin código ejecutable        |
| `relation_rules`     | Configurar relaciones y dependencias posteriores |
| `preservation_rules` | Explicar datos sin equivalente                   |

Los IDs de reglas son estables y el orden canónico evita que reordenar la
interfaz cambie una huella. Los mapeos v1 siguen siendo válidos y solo se
actualizan cuando el operador guarda la conversión.

## Equivalencias ampliadas

| phpIPAM                        | NetBox o conducta                                                                                                                  |
| ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------- |
| Customers                      | Tenant; Contact solo con Contact Role aprobado                                                                                     |
| Sections e IP Tags             | Tags opt-in y reglas de estado                                                                                                     |
| Locations                      | Site o Location subordinada a Site                                                                                                 |
| Racks                          | Rack con Site/Location resuelto                                                                                                    |
| Device Types                   | Device Role, nunca modelo físico                                                                                                   |
| Devices                        | Device con Site, Role y Device Type obligatorios                                                                                   |
| Puertos y MACs                 | Interface y MAC Address cuando son válidos                                                                                         |
| Circuitos                      | Provider, Circuit Type, Circuit y terminaciones seguras; los tipos requieren un dump porque la API oficial no expone esa colección |
| BGP                            | ASN; las sesiones se preservan                                                                                                     |
| NAT                            | Solo relación IP↔IP estática 1:1 confirmada                                                                                        |
| Hostnames                      | `dns_name` de IP Address                                                                                                           |
| DNS autoritativo y extensiones | Preservación o custom field aprobado                                                                                               |

Los objetos auxiliares ausentes se proponen, pero solo entran al plan después
de una aprobación explícita.

## Límites de seguridad

- Un Device sin Site, Role o Device Type bloquea el plan.
- Device Type exige Manufacturer; Rack y Location exigen Site.
- Una IP de device sin puerto queda sin interfaz y genera warning.
- Una MAC inválida o sin puerto se preserva con el motivo; valores válidos
  repetidos en el mismo puerto no crean interfaces o MAC duplicadas.
- Primary IP requiere una única IP migrada y asignada.
- PAT, NAT con puertos, NAT many-to-many y sesiones BGP no se convierten
  parcialmente.
- Las terminaciones de circuito exigen ubicación inequívoca; no se inventan
  cables.
- Las tablas desconocidas permanecen fuera de la whitelist y se informa su
  exclusión sin preservar posibles secretos.

## Plan v3 y orden

El plan v3 separa acciones de objetos y relaciones y conserva referencias
diferidas, checkpoints y verificación idempotente:

```text
custom fields/tags/tenants
→ sites/locations
→ racks
→ manufacturers/device types/roles
→ devices/interfaces/MACs
→ providers/circuits
→ RIRs/ASNs
→ VRFs/VLANs/prefixes/IPs
→ assignments/primary IP/NAT
```

La aplicación continúa exclusivamente por API REST, con locks, ETag cuando
está disponible, checkpoints y vínculos persistentes. La verificación
canonicaliza diferencias entre campos REST de escritura y representación, como
`termination_id`/`termination`, listas de objetos y enteros serializados como
decimales, sin aceptar valores semánticamente distintos.

## Compatibilidad

- phpIPAM 1.5 a 1.8;
- NetBox 4.4 a 4.6;
- sandbox fijado en NetBox 4.6.1.

El adaptador API de phpIPAM usa solo controllers oficiales. Los módulos
exclusivos del dump aparecen explícitamente en la cobertura.

Consulta [Arquitectura](ARQUITECTURA.es.md) y
[ADR-002](adr/0002-mapping-v2-plan-v3.es.md).
