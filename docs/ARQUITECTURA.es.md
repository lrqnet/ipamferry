# Arquitectura de IpamFerry

IpamFerry es una aplicación Laravel 13/PHP 8.4 con Inertia 3, React 19,
TypeScript y Tailwind CSS 4, distribuida con FrankenPHP/Caddy y Docker Compose.
El panel web es el flujo principal; los comandos Artisan reutilizan los mismos
servicios de dominio.

## Flujo de migración

```text
discover → normalize → map → plan → approve → apply → verify → bundle
```

El descubrimiento lee la API oficial de phpIPAM o analiza un `mysqldump`
únicamente como datos. El SQL nunca se ejecuta. El descubrimiento de NetBox usa
la API REST, recorre la paginación y consulta `OPTIONS` para validar campos
obligatorios, opciones y límites de la versión detectada.

El alcance automático incluye equivalencias seguras de IPAM, Tenancy, DCIM,
Circuits y ASN. PAT, sesiones BGP, DNS autoritativo, permisos y extensiones sin
equivalente seguro permanecen en el informe de preservación.

Mapping Studio edita JSON versionado sin código ejecutable. Las sugerencias
deterministas requieren aceptación, las revisiones optimistas evitan
sobrescrituras y el preview temporal usa el mismo planificador. Los objetos
existentes se reutilizan por defecto y los updates son opt-in.

## Identidad y seguridad de ejecución

| Objeto NetBox                 | Identidad principal                       | Restricción adicional                 |
| ----------------------------- | ----------------------------------------- | ------------------------------------- |
| VRF                           | RD; nombre inequívoco sin RD              | RD único                              |
| VLAN Group                    | ámbito + nombre                           | ámbito + slug                         |
| VLAN                          | grupo + VID                               | grupo + nombre                        |
| Prefix                        | VRF + prefijo canónico                    | dependencias de VRF/VLAN              |
| IP Address                    | VRF + dirección con máscara               | dependencias de VRF/prefijo           |
| Custom Field                  | nombre                                    | tipo y object types compatibles       |
| Site/Location/Rack            | slug o nombre dentro de Site/Location     | jerarquía y Site obligatorios         |
| Manufacturer/Device Type/Role | slug; Device Type usa Manufacturer + slug | dependencias DCIM                     |
| Device                        | Site + nombre                             | Site, Role y Device Type obligatorios |
| Interface/MAC                 | Device + nombre; dirección MAC canónica   | padre y formato válidos               |
| Provider/Circuit Type/Circuit | slug o CID                                | terminaciones inequívocas             |
| RIR/ASN                       | slug o ASN                                | creación de RIR aprobada              |

Los planes son inmutables y quedan vinculados a los snapshots, el mapeo, el
idioma del artefacto, la instancia NetBox y la versión de API. La aprobación
corresponde a una huella exacta. Cada acción tiene un checkpoint persistente y
los vínculos conservan el ID exacto del objeto NetBox.

Antes de escribir, el ejecutor vuelve a comprobar el destino. Detecta cambios
con `last_updated` y, en NetBox 4.6+, `ETag`/`If-Match`. Una respuesta perdida
de POST o PATCH solo se recupera si el objeto coincide exactamente con el
payload aprobado. Repetir un plan aplicado o verificado devuelve la misma
ejecución sin duplicar datos.

## Sandbox desechable

El perfil opcional `sandbox` ejecuta NetBox 4.6.1 fijado, PostgreSQL y Redis en
una red interna. No publica su API ni sus bases en el host. El token v2 interno
se genera automáticamente y no entra en los datos de la aplicación.

El plan es específico del destino. El ensayo y producción usan planes hermanos:

```text
misma fuente + mismo mapeo + snapshot sandbox    → plan de ensayo
misma fuente + mismo mapeo + snapshot producción → plan de producción
```

Un plan del sandbox no puede aplicarse en producción porque la huella del
destino es diferente. La aplicación y la verificación quedan fijadas al destino
registrado en el plan; pasar del ensayo a producción requiere un nuevo plan
hermano. Después de verificar con éxito, la planificación permanece
deshabilitada hasta actualizar el snapshot del destino, evitando usar el
inventario anterior a la aplicación.

## Secretos, auditoría y bundles

Los tokens phpIPAM/NetBox solo existen en memoria. No se guardan en PostgreSQL,
colas, eventos, logs, informes ni ZIP. Los dumps son privados, se eliminan
después de normalizar y se purgan tras fallos según la retención configurada.

Los uploads PHP usan un directorio del volumen privado y no el filesystem raíz
de solo lectura. El contenedor admite el límite predeterminado de 1 GiB y
Laravel sigue aplicando `IPAMFERRY_DUMP_MAX_BYTES`. El login tiene rate limit
con una clave anonimizada de correo/IP, y el locale solo cambia en pantalla
después de persistir el cookie o la preferencia del usuario.

Los bundles contienen versiones de mapping y plan, `mapping.json` y
`plan.json` canónicos, cobertura, referencias propuestas, decisiones de
preservación, informes localizados, checkpoints y eventos saneados. Las claves
para máquinas y la salida CLI permanecen en inglés. IpamFerry nunca genera ni
modifica un dump PostgreSQL de NetBox.

La matriz soportada es phpIPAM 1.5–1.8 y NetBox 4.4–4.6; el sandbox está fijado
en 4.6.1. Consulta [Mapping Studio](MAPPING-STUDIO.es.md),
[ADR-002](adr/0002-mapping-v2-plan-v3.es.md) y la referencia detallada en
portugués [ARQUITETURA.md](ARQUITETURA.md).
