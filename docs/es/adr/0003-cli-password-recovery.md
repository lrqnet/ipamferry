# ADR-0003: Recuperación de contraseña solo por comando

> **Idioma:** [English](../../adr/0003-cli-password-recovery.md) · [Português (Brasil)](../../pt-BR/adr/0003-cli-password-recovery.md) · [Español](0003-cli-password-recovery.md)

## Contexto

IpamFerry suele instalarse como appliance privado con un owner. No existe una dependencia configurada de correo saliente y un flujo público incompleto de reset añadiría superficie de ataque innecesaria.

## Decisión

La recuperación de contraseña es una operación CLI interactiva disponible solo para un administrador del host Docker:

```bash
docker compose exec -it app php artisan ipamferry:reset-password
```

El comando usa la política de contraseña del setup, nunca acepta una contraseña mediante argumentos, ambiente o stdin, exige confirmación y ejecuta invalidación atómica de credencial/sesión. Las rutas Fortify de reset por email se desactivan. Un reset exitoso crea solo un evento mínimo con ID de usuario, tipo, timestamp y origen `cli`.

## Consecuencias

La autoridad de recuperación es explícita: el administrador necesita acceso al host/Docker. Los usuarios no pueden restablecer por email. El almacenamiento existente de tokens de reset permanece por compatibilidad, pero se limpia para la cuenta objetivo después de la recuperación.
