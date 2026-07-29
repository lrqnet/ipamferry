# ADR-0003: Recuperação de senha somente por comando

> **Idioma:** [English](../../adr/0003-cli-password-recovery.md) · [Português (Brasil)](0003-cli-password-recovery.md) · [Español](../../es/adr/0003-cli-password-recovery.md)

## Contexto

IpamFerry costuma ser instalado como appliance privado com um owner. Não há dependência configurada de email de saída, e um fluxo público incompleto de reset acrescentaria superfície de ataque desnecessária.

## Decisão

Recuperação de senha é operação CLI interativa disponível somente para administrador do host Docker:

```bash
docker compose exec -it app php artisan ipamferry:reset-password
```

O comando usa a política de senha do setup, nunca aceita senha por argumentos, ambiente ou stdin, exige confirmação e executa invalidação atômica de credencial/sessão. Rotas Fortify de reset por email são desativadas. Um reset bem-sucedido cria apenas evento mínimo com ID do usuário, tipo, timestamp e origem `cli`.

## Consequências

A autoridade de recuperação é explícita: o administrador precisa de acesso ao host/Docker. Usuários não podem redefinir por email. O armazenamento existente de tokens de reset permanece por compatibilidade, mas é limpo para a conta alvo após recuperação.
