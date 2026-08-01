# Changelog

> **Idioma:** [English](../../CHANGELOG.md) · [Português (Brasil)](../pt-BR/CHANGELOG.md) · [Español](CHANGELOG.md)

Todos los cambios relevantes de este proyecto se documentan en este archivo.

## [0.3.1] - 2026-08-01

### Corregido

- El checksum de la versión ahora verifica directamente el artefacto `compose.yaml` descargado.

## [0.3.0] - 2026-08-01

### Añadido

- Pie de página global responsivo con repositorio del proyecto, autor, enlace a GitHub Sponsors, versión instalada y controles de actualización exclusivos del owner.
- Comprobación diaria y privada de versiones estables, además de un flujo seguro de actualización desde el panel con checksum verificado y Compose fijado por digest.
- Servicio updater dedicado con privilegio mínimo, estado persistente, protección frente a actualizaciones simultáneas, bloqueo durante migraciones e informe de fallos de health check.

### Corregido

- La marca del encabezado y el selector de idioma usan todo el ancho disponible y no quedan juntos en pantallas estrechas.
- El updater usa un volumen privado dedicado para funcionar correctamente entre contenedores Laravel no-root en Docker Desktop y hosts Linux.

## [0.2.0] - 2026-07-28

### Añadido

- Mapping Studio visual con sugerencias deterministas, catálogo sanitizado, preview asíncrono, JSON Expert, undo/redo y concurrencia optimista.
- Schema de mapping v2 y plan v3 con acciones de objeto/relación, referencias diferidas y canonicalización estable.
- Cobertura segura de recursos core de NetBox para tenancy, DCIM, circuits e IPAM.
- Comando interactivo de recuperación de contraseña para administrador del host, con invalidación de sesiones y evento de seguridad.
- Documentación pública en inglés como principal y espejos en portugués de Brasil y español.

### Cambiado

- Se desactivaron las rutas Fortify de reset por email; la recuperación no depende de correo.
- Los objetos auxiliares faltantes requieren aprobación explícita antes de entrar en un plan.

### Corregido

- Mapping Studio muestra solo el selector global de idioma; el idioma del informe y bundle sigue siendo una configuración del proyecto.

## [0.1.0] - 2026-07-24

### Añadido

- Base Laravel/Inertia para el workbench de migración phpIPAM a NetBox.
- Stack Compose segura, discovery API/dump, planificación, bundles auditables y sandbox NetBox.
