# Operação e releases

> **Idioma:** [English](../RELEASE.md) · [Português (Brasil)](RELEASE.md) · [Español](../es/RELEASE.md)

## Instalação e atualização

Instale o `compose.yaml` da release com `docker compose up -d --wait`. Para um checkout do código use o overlay de desenvolvimento:

```bash
docker compose --file compose.yaml --file compose.dev.yaml up -d --build --wait
```

Confirme a saúde com `docker compose ps`. `app` é o único serviço exposto à LAN. Não exponha PostgreSQL, NetBox sandbox ou redes internas.

## Recuperação de senha

A recuperação exige acesso de administrador ao host Docker e terminal interativo:

```bash
docker compose exec -it app php artisan ipamferry:reset-password
```

Quando existe exatamente um owner ativo, não é necessário argumento de email. Sem owner único ou com vários owners ativos, informe o email exato da conta:

```bash
docker compose exec -it app php artisan ipamferry:reset-password owner@example.test
```

O comando nunca lê senha por argumento, variável de ambiente ou stdin. Ele solicita senha oculta e confirmação, aplica a política de senha do projeto, rotaciona o remember token, remove sessões ativas e tokens pendentes de reset, e registra evento de segurança mínimo com origem `cli`. Recusa contas inativas. Não há recuperação por web ou email.

## Ensaio no sandbox

```bash
docker compose --profile sandbox up -d --wait
```

Gere um plano novo para sandbox e outro plano irmão novo para produção. Nunca aplique um plano sandbox em produção.
