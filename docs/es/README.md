# IpamFerry

> **Idioma:** [English](../../README.md) · [Português (Brasil)](../pt-BR/README.md) · [Español](README.md)

IpamFerry es un workbench autoalojado para planificar y aplicar migraciones phpIPAM a NetBox. Lee la API oficial de phpIPAM o un archivo SQL `mysqldump`, produce un plan revisable, aplica solo mediante la API oficial de NetBox y exporta un bundle de auditoría.

> [!WARNING]
> Los datasets IPAM y tokens API son sensibles. Restrinja el acceso a una red administrativa y use siempre HTTPS.

## Instalación

Descargue el `compose.yaml` de una versión y ejecute:

```bash
docker compose up -d --wait
docker compose ps
docker compose exec app php artisan ipamferry:installation-token
```

Abra `https://SU_SERVIDOR`, use el token para crear el primer owner y termine el setup. Compose genera claves y contraseñas internas en volúmenes Docker; no se requiere `.env` operativo.

## Recuperación de contraseña local

La recuperación se realiza deliberadamente por el administrador del host Docker, no por correo:

```bash
docker compose exec -it app php artisan ipamferry:reset-password
```

El comando requiere una terminal interactiva. Cuando existe exactamente un owner activo, selecciona esa cuenta; en otro caso indique el email exacto:

```bash
docker compose exec -it app php artisan ipamferry:reset-password owner@example.test
```

Solicita confirmación y una contraseña oculta dos veces, aplica la política de 14–128 caracteres, invalida sesiones de base de datos y tokens pendientes y rota el remember token. La contraseña nunca se acepta por argumento, ambiente o stdin. Por ello, el acceso de administrador al host/Docker es la autoridad de recuperación.

## Flujo de migración

1. Cree un proyecto y elija **API phpIPAM** o **mysqldump SQL**.
2. Descubra origen y destino; los tokens permanecen solo en memoria de solicitud/job.
3. Use **Mapping Studio** y ejecute un preview no aplicable.
4. Genere un plan específico para el destino y resuelva conflictos.
5. Un `owner` o `administrator` aprueba el fingerprint exacto.
6. Aplique mediante la API REST de NetBox con checkpoints persistentes.
7. Verifique por identificadores y claves naturales y descargue el bundle auditable.

La automatización cubre equivalentes core seguros, incluidos tenants, tags, sites, locations, racks, manufacturers, device roles/types, devices, interfaces, MACs, providers, circuits, RIRs, ASNs, VRFs, VLAN groups/VLANs, prefixes, IP addresses y custom fields aprobados. Primary IP, terminaciones y NAT estático 1:1 solo se aplican con coincidencia inequívoca y aprobación.

PAT, sesiones BGP, DNS autoritativo, permisos y extensiones sin equivalente seguro se preservan y explican; nunca se convierten parcialmente ni se descartan en silencio.

Para ensayo:

```bash
docker compose --profile sandbox up -d --wait
```

Un plan de sandbox nunca puede reutilizarse en producción. Redescubra el NetBox de producción y genere un plan hermano para él.

## Desarrollo

PHP 8.4, Composer y Node 22 son necesarios fuera de Docker:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
php artisan test
```

La salida CLI y claves JSON reejecutables permanecen en inglés. Consulte el [índice de documentación](DOCUMENTATION.md) para arquitectura, Mapping Studio, operaciones, versiones y ADRs.

## Licencia

IpamFerry está licenciado bajo [AGPL-3.0-only](../../LICENSE).
