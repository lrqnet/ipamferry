# Validación de lanzamiento

> **Idioma:** [English](../VALIDATION.md) · [Português (Brasil)](../pt-BR/VALIDATION.md) · [Español](VALIDATION.md)

IpamFerry mantiene un laboratorio local desechable para validar un origen phpIPAM real, un `mysqldump` real y la ruta de la API REST de NetBox. Nunca reutiliza la instalación, los volúmenes ni las credenciales de un operador.

## Ejecución local

```bash
./scripts/lab-test.sh v1.8.1
```

El laboratorio crea credenciales temporales en volúmenes Docker, expone solo interfaces de prueba en loopback, valida la API phpIPAM de solo lectura, crea un `mysqldump --single-transaction` en el directorio ignorado `tmp/lab/` y elimina los volúmenes y archivos generados al terminar.

## Dumps externos anonimizados y protegidos

Un dump anonimizado aprobado puede validarse contra un sandbox de NetBox desechable con un archivo de mapeo explícito:

```bash
./scripts/lab-external-dump.sh /ruta-segura/origen.sql /ruta-segura/mapeo.json
```

El comando monta ambos archivos como solo lectura, interpreta el dump únicamente como datos, crea y verifica un plan aprobado mediante la API de NetBox, reanuda checkpoints de una acción, repite la aplicación para probar idempotencia, inspecciona el bundle en busca de campos sensibles y destruye volúmenes, temporales y bundle al finalizar. No se conecta a la instancia phpIPAM original.

GitHub Actions ofrece la misma ruta solo mediante el entorno protegido `external-corpus-validation` y un workflow manual. Los secrets `IPAMFERRY_EXTERNAL_DUMP_B64` e `IPAMFERRY_EXTERNAL_MAPPING_B64` deben contener un corpus anonimizado y mapeo aprobados. Se aplican los límites de tamaño de secrets de GitHub; use el comando local o un runner privado aprobado para corpus mayores. El workflow no publica dump, mapeo, bundle, trace, captura ni log de contenedor.

## Matriz de compatibilidad

Las etiquetas y los digests inmutables están en [`tests/lab/compatibility-manifest.json`](../../tests/lab/compatibility-manifest.json). La validación requiere phpIPAM 1.5.2, 1.7.4 y 1.8.1 con NetBox 4.6.1 en recorrido profundo, y phpIPAM 1.8.1 con NetBox 4.4.10 y 4.5.10 en smoke.

## Cobertura y exclusiones

[`tests/lab/coverage-manifest.json`](../../tests/lab/coverage-manifest.json) clasifica cada tabla conocida como `migrated`, `preserved`, `sensitive_excluded`, `unsupported` o `not_available_in_version`. Las credenciales, usuarios, sesiones, bóvedas, aplicaciones API y configuraciones de agentes de exploración nunca se incluyen en planes, bundles, informes, artefactos de CI ni documentación pública.

[`tests/lab/scenario-matrix.json`](../../tests/lab/scenario-matrix.json) define los casos de aceptación a nivel de datos: límites de CIDR, canonicalización IPv6, identidades de VRF/VLAN, jerarquía, límites de texto, campos personalizados, tenancy, DCIM, circuitos, NAT, drift del destino, recuperación y sanitización. Cada caso declara si debe migrarse, preservarse, bloquearse para revisión o rechazarse de forma segura.

Los dumps sin procesar, bases de datos, bundles generados, traces de Playwright, capturas y logs de contenedores sin sanitizar no se conservan como artefactos de CI.

## Estado de validación local

La matriz es una puerta activa de release: un escenario solo está completo cuando su evidencia ejecutable aprueba en todas las combinaciones de compatibilidad requeridas. El 26-07-2026, las cinco combinaciones de compatibilidad listadas aprobaron usando tanto la API phpIPAM de solo lectura como un `mysqldump` real. Cada ejecución aplicó, verificó y reaplicó de forma segura 76 acciones centrales y 109 acciones extendidas aprobadas, sin duplicación. El recorrido profundo con NetBox 4.6.1 también reanudó la misma ejecución real mediante 76 checkpoints persistidos de una acción. El corpus crea y lee campos personalizados aprobados de NetBox y sus conjuntos de opciones, preserva variantes inseguras de NAT y nombres DNS inválidos, y confirma que no se envían a NetBox.
