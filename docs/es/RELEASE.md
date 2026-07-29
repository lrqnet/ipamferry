# Operaciones y versiones

> **Idioma:** [English](../RELEASE.md) · [Português (Brasil)](../pt-BR/RELEASE.md) · [Español](RELEASE.md)

## Instalación y actualización

Instale el `compose.yaml` de la versión con `docker compose up -d --wait`. Para un checkout del código use el overlay de desarrollo:

```bash
docker compose --file compose.yaml --file compose.dev.yaml up -d --build --wait
```

Confirme la salud con `docker compose ps`. `app` es el único servicio expuesto a la LAN. No exponga PostgreSQL, NetBox sandbox ni redes internas.

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
