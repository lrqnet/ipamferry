# Operação e releases

> **Idioma:** [English](../RELEASE.md) · [Português (Brasil)](RELEASE.md) · [Español](../es/RELEASE.md)

## Instalação e atualização

Instale o `compose.yaml` da release com `docker compose up -d --wait`. Para um checkout do código use o overlay de desenvolvimento:

```bash
docker compose --file compose.yaml --file compose.dev.yaml up -d --build --wait
```

Confirme a saúde com `docker compose ps`. `app` é o único serviço exposto à LAN. Não exponha PostgreSQL, NetBox sandbox ou redes internas.

## Atualizações pelo painel

O rodapé mostra a versão instalada em todas as páginas. A cada 24 horas, o IpamFerry consulta a API pública do GitHub pela release estável mais recente; nenhum identificador da instalação, dado de migração, credencial ou telemetria é enviado. Um owner também pode selecionar **Verificar atualizações**.

Somente um owner pode confirmar uma atualização. O atualizador baixa o `compose.yaml` da release, valida seu checksum SHA-256 publicado e aceita apenas imagem IpamFerry fixada por digest. Ele recusa pre-releases, downgrade, atualizações concorrentes e atualizações durante descoberta, planejamento, aplicação ou verificação. A aplicação reinicia brevemente após a atualização.

O serviço `updater` possui acesso ao socket Docker para recriar os serviços da aplicação IpamFerry; isso equivale a autoridade de administrador do host Docker. Ele não tem acesso à rede e não é exposto à LAN. Não habilite `IPAMFERRY_UPDATES_ENABLED` em um host no qual owners não possam ter essa capacidade operacional. Se a nova aplicação não ficar saudável, o rollback automático não é tentado porque migrations de banco podem ser irreversíveis; inspecione `docker compose logs updater` e siga as notas da release.

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
