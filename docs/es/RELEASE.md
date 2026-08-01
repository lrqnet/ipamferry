# Operaciones y versiones

> **Idioma:** [English](../RELEASE.md) · [Português (Brasil)](../pt-BR/RELEASE.md) · [Español](RELEASE.md)

## Instalación y actualización

Instale el `compose.yaml` de la versión con `docker compose up -d --wait`. Para un checkout del código use el overlay de desarrollo:

```bash
docker compose --file compose.yaml --file compose.dev.yaml up -d --build --wait
```

Confirme la salud con `docker compose ps`. `app` es el único servicio expuesto a la LAN. No exponga PostgreSQL, NetBox sandbox ni redes internas.

## Actualizaciones desde el panel

El pie de página muestra la versión instalada en todas las páginas. Cada 24 horas, IpamFerry consulta la API pública de GitHub para encontrar la versión estable más reciente; no se envía ningún identificador de instalación, dato de migración, credencial ni telemetría. Un owner también puede seleccionar **Buscar actualizaciones**.

Solo un owner puede confirmar una actualización. El actualizador descarga el `compose.yaml` de la versión, valida su checksum SHA-256 publicado y acepta únicamente una imagen IpamFerry fijada por digest. Rechaza pre-releases, downgrades, actualizaciones simultáneas y actualizaciones durante descubrimiento, planificación, aplicación o verificación. La aplicación se reinicia brevemente después de actualizar.

El servicio `updater` tiene acceso al socket Docker para recrear los servicios de la aplicación IpamFerry; esto equivale a autoridad de administrador del host Docker. No tiene acceso a la red y no se expone a la LAN. No active `IPAMFERRY_UPDATES_ENABLED` en un host donde los owners no deban tener esta capacidad operativa. Si la nueva aplicación no queda saludable, no se intenta rollback automático porque las migrations de base de datos pueden ser irreversibles; revise `docker compose logs updater` y siga las notas de la versión.

## Recuperación de contraseña

La recuperación exige acceso de administrador al host Docker y una terminal interactiva:

```bash
docker compose exec -it app php artisan ipamferry:reset-password
```

Cuando hay exactamente un owner activo no se requiere email. Si no hay un owner único o hay varios owners activos, proporcione el email exacto de la cuenta:

```bash
docker compose exec -it app php artisan ipamferry:reset-password owner@example.test
```

El comando nunca lee una contraseña por argumento, variable de entorno o stdin. Solicita contraseña oculta y confirmación, aplica la política del proyecto, rota el remember token, elimina sesiones activas y tokens pendientes de reset y registra un evento mínimo de seguridad con origen `cli`. Rechaza cuentas inactivas. No existe recuperación por web ni email.

## Ensayo en sandbox

```bash
docker compose --profile sandbox up -d --wait
```

Genere un plan nuevo para sandbox y otro plan hermano nuevo para producción. Nunca aplique un plan sandbox a producción.
