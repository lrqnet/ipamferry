# IpamFerry

> **Idioma:** [English](../../README.md) · [Português (Brasil)](README.md) · [Español](../es/README.md)

IpamFerry é um workbench self-hosted para planejar e aplicar migrações phpIPAM para NetBox. Ele lê a API oficial phpIPAM ou um arquivo SQL `mysqldump`, produz plano revisável, aplica somente pela API oficial NetBox e exporta bundle de auditoria.

> [!WARNING]
> Datasets IPAM e tokens API são sensíveis. Restrinja o acesso à rede administrativa e use HTTPS.

## Instalação

Baixe o `compose.yaml` de uma release e execute:

```bash
docker compose up -d --wait
docker compose ps
docker compose exec app php artisan ipamferry:installation-token
```

Abra `https://SEU_SERVIDOR`, use o token para criar o primeiro owner e conclua o setup. Compose gera chaves e senhas internas em volumes Docker; não é necessário `.env` operacional.

## Recuperação de senha local

Recuperação é deliberadamente feita pelo administrador do host Docker, não por email:

```bash
docker compose exec -it app php artisan ipamferry:reset-password
```

O comando exige terminal interativo. Quando há exatamente um owner ativo, ele seleciona essa conta; caso contrário, informe o email exato:

```bash
docker compose exec -it app php artisan ipamferry:reset-password owner@example.test
```

Ele solicita confirmação e senha oculta duas vezes, aplica a política de 14–128 caracteres, invalida sessões no banco e tokens pendentes, e rotaciona o remember token. A senha nunca é aceita por argumento, ambiente ou stdin. Portanto, acesso de administrador ao host/Docker é a autoridade de recuperação.

## Fluxo de migração

1. Crie projeto e escolha **API phpIPAM** ou **mysqldump SQL**.
2. Descubra origem e destino; tokens permanecem apenas na memória de requisição/job.
3. Use **Mapping Studio** e execute preview não aplicável.
4. Gere plano específico ao destino e resolva conflitos.
5. Um `owner` ou `administrator` aprova o fingerprint exato.
6. Aplique pela API REST do NetBox com checkpoints persistentes.
7. Verifique por identificadores e chaves naturais e baixe o bundle auditável.

A automação cobre equivalentes core seguros, incluindo tenants, tags, sites, locations, racks, manufacturers, device roles/types, devices, interfaces, MACs, providers, circuits, RIRs, ASNs, VRFs, VLAN groups/VLANs, prefixes, IP addresses e custom fields aprovados. Primary IP, terminações e NAT estático 1:1 só são aplicados com correspondência inequívoca e aprovação.

PAT, sessões BGP, DNS autoritativo, permissões e extensões sem equivalente seguro são preservados e explicados; jamais convertidos parcialmente ou descartados silenciosamente.

Para ensaio:

```bash
docker compose --profile sandbox up -d --wait
```

Um plano de sandbox nunca pode ser reutilizado em produção. Redescubra o NetBox de produção e gere plano irmão para ele.

## Desenvolvimento

PHP 8.4, Composer e Node 22 são necessários fora do Docker:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
php artisan test
```

Saída CLI e chaves JSON reexecutáveis permanecem em inglês. Consulte o [índice de documentação](DOCUMENTATION.md) para arquitetura, Mapping Studio, operação, releases e ADRs.

## Licença

IpamFerry está licenciado sob [AGPL-3.0-only](../../LICENSE).
