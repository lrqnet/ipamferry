# Plan histórico

> **Idioma:** [English](../PLAN.md) · [Português (Brasil)](../pt-BR/PLAN.md) · [Español](PLAN.md)

Este documento registra la dirección de producto adoptada para IpamFerry 0.2. No es un plan de migración ejecutable; el comportamiento actual se define por la aplicación, pruebas, [arquitectura](ARCHITECTURE.md) y ADRs.

## Dirección

El panel es la interfaz principal y los comandos Artisan son la interfaz de automatización. La migración se realiza siempre mediante la API de NetBox. La entrada phpIPAM es su API oficial o un `mysqldump` analizado en modo de solo lectura; ningún volcado se ejecuta.

## Alcance de entrega

- instalación Compose segura con PostgreSQL aislado y sandbox opcional;
- discovery de origen/destino, Mapping Studio, preview, planes inmutables, apply con checkpoints, verificación y bundle auditable;
- cobertura segura de recursos core de NetBox e informe de preservación para datos sin soporte;
- documentación pública en inglés con espejos completos en portugués de Brasil y español; y
- recuperación de contraseña CLI por administrador del host, sin dependencia de email.

## Exclusiones explícitas

Plugins, conversión de DNS autoritativo, conversión de sesiones BGP, PAT, expresiones ejecutables arbitrarias de mapeo, escritura directa en la base de datos NetBox y aceptación automática de sugerencias permanecen fuera de alcance.
