# Arquitetura e garantias de migração

IpamFerry é uma aplicação Laravel 13/PHP 8.4 com Inertia 3, React 19,
TypeScript e Tailwind CSS 4. A distribuição usa FrankenPHP/Caddy e Docker
Compose. A interface web é o fluxo principal e os comandos Artisan reutilizam
os mesmos serviços de domínio.

## Pipeline

```text
discover → normalize → map → plan → approve → apply → verify → bundle
```

`discover` lê a API oficial do phpIPAM ou analisa um `mysqldump` como dados.
O dump nunca é executado. A descoberta do NetBox usa REST, pagina todas as
coleções e consulta `OPTIONS` para capturar campos obrigatórios, choices e
limites da versão encontrada.

`normalize` converte os registros para um snapshot intermediário versionado.
O escopo automático atual é VRF, VLAN Group, VLAN, Prefix, IP Address e custom
fields explicitamente mapeados. Dados sem conversão segura permanecem no
snapshot de preservação.

`map` aceita somente uma política JSON versionada. As ações permitidas para
custom fields são copiar, ignorar, valor fixo, concatenar, normalizar e lookup.
Não há PHP, JavaScript, template ou expressão arbitrária.

`plan` é somente leitura. O resultado contém ações ordenadas, conflitos,
avisos, dados preservados, fingerprints e a identidade do destino. `apply`
recusa qualquer plano alterado, desatualizado, não aprovado ou vinculado a
outra instância/versão do NetBox.

## Identidades e dependências

| Objeto NetBox | Identidade primária do IpamFerry | Restrições adicionais verificadas |
| --- | --- | --- |
| VRF | RD; sem RD, nome inequívoco | RD único |
| VLAN Group | escopo + nome | escopo + slug |
| VLAN | grupo + VID | grupo + nome |
| Prefix | VRF + prefixo canônico | dependências de VRF/VLAN |
| IP Address | VRF + endereço com máscara | dependências de VRF/prefixo |
| Custom Field | nome | tipo e object types compatíveis |

Uma correspondência múltipla, uma referência ausente ou duas origens
reivindicando a mesma identidade gera conflito impeditivo. Restrições de
comprimento, mínimo, máximo, campo obrigatório e choice informadas pelo NetBox
também são validadas antes de qualquer escrita.

Depois da primeira aplicação, `migration_object_links` associa
`(projeto, instância phpIPAM, tipo, source_id, instância NetBox)` ao ID exato
do destino. O vínculo tem precedência sobre chaves naturais em migrações
posteriores, mas um destino removido ou de tipo incompatível bloqueia o plano.

A ordem é calculada como grafo: custom fields, VRFs/VLAN Groups, VLANs,
Prefixes e IP Addresses. Ciclos não são executados.

## Criação, reutilização e atualização

- `create`: nenhum destino existe na descoberta;
- `reuse`: existe exatamente um destino; diferenças são mostradas, mas
  preservadas;
- `update`: somente campos autorizados na política entram no PATCH;
- `ignore`: decisão explícita ou objeto sem equivalência automática.

Updates são opt-in por tipo e campo. O executor consulta novamente o destino
antes de cada mutação. Um objeto que surge depois do plano exige novo
planejamento. Para PATCH, `last_updated` detecta drift e `ETag`/`If-Match` é
usado quando o NetBox o fornece.

Cada ação possui checkpoint persistente. Se a conexão cair depois de um POST
ou PATCH, a retomada só considera a operação recuperada quando o objeto
encontrado coincide integralmente com o payload aprovado. Caso contrário, a
execução para em segurança. Repetir um plano aplicado ou verificado devolve a
mesma execução.

## Estados e bloqueios

```text
draft → discovering → discovered → planning → planned → approved
      → applying → applied → verifying → verified
```

Falhas podem produzir `failed`, `partially_applied` ou
`verification_failed`. Uma execução ativa ou falha bloqueia nova descoberta,
alteração de mapeamento e planejamento. O operador deve retomar e verificar o
mesmo plano. O bloqueio por projeto impede duas operações concorrentes.

A aprovação é vinculada ao fingerprint exato e só pode ser feita por `owner`
ou `administrator`. `operator` pode descobrir, planejar, aplicar e verificar;
`reader` apenas consulta e baixa artefatos.

## Sandbox

O perfil `sandbox` sobe NetBox 4.6.1, PostgreSQL e Redis em uma rede interna,
sem publicar banco ou API no host. O token v2 é gerado pelo `init`, lido apenas
em memória e garantido de forma idempotente no NetBox.

Planos são específicos do inventário de destino. Um ensaio e uma migração real
usam planos irmãos:

```text
mesma origem + mesmo mapeamento + snapshot sandbox  → plano de ensaio
mesma origem + mesmo mapeamento + snapshot produção → plano de produção
```

O plano do sandbox não pode ser aplicado em produção porque o fingerprint da
instância e da versão da API é diferente.
Depois da aprovação, `apply` e `verify` fixam o seletor de destino no valor
gravado no plano; trocar de sandbox para produção exige redescobrir o destino
e gerar um plano irmão.

## Serviços Compose

- `init`: sem rede, gera segredos e prepara ownership dos volumes;
- `postgres`: banco principal sem porta publicada;
- `database-init`: limpa caches regeneráveis, cria role/banco e migra schema;
- `app`: único serviço exposto, executado como UID 20000;
- `worker`: fila persistente `database`;
- `scheduler`: retenção de dumps;
- `sandbox-netbox`, `sandbox-postgres`, `sandbox-redis`: perfil opcional
  isolado.

O filesystem dos serviços IpamFerry é read-only, capacidades são removidas,
`no-new-privileges` é aplicado e apenas volumes mínimos são graváveis. O
PostgreSQL sandbox roda diretamente como UID 999, com o volume preparado pelo
`init`, permitindo também remover todas as capabilities.

Uploads PHP usam `/app/storage/framework/uploads`, dentro do volume privado,
em vez de `/tmp` no filesystem read-only. `upload_max_filesize` e
`post_max_size` suportam o limite padrão de 1 GiB; Laravel ainda aplica o
limite configurado por `IPAMFERRY_DUMP_MAX_BYTES`. Um `tmpfs` pequeno e
`noexec` atende arquivos temporários que não são dumps.

## Segredos, auditoria e artefatos

Tokens phpIPAM/NetBox não são gravados no PostgreSQL, fila, eventos, logs,
relatórios ou bundles. Processos PHP iniciados por `docker compose exec`
carregam `APP_KEY` e a senha interna do banco do arquivo privado gerado pelo
Compose; não é necessário `.env`.

O login tem rate limit por combinação anonimizada de e-mail e IP. A escolha de
idioma só altera a interface após a persistência do cookie/usuário ser
confirmada, evitando projetos criados com locale divergente do exibido.

O dump bruto fica em armazenamento privado e é apagado logo após normalização.
Falhas são removidas pelo scheduler conforme `IPAMFERRY_DUMP_RETENTION_HOURS`.

Cada evento relevante registra ator, projeto, plano, execução, tipo, nível e
contexto sanitizado. O bundle ZIP inclui:

- manifesto com schema, versão, locale e fingerprints;
- `mapping.json` e `plan.json` com chaves canônicas em inglês;
- relatório HTML/JSON e preservação no locale imutável do plano;
- resultados/checkpoints e eventos de auditoria;
- payloads reexecutáveis sem credenciais.

Não é produzido dump PostgreSQL do NetBox.

## Compatibilidade segura

O cliente aceita tokens legados (`Token`) e v2 (`Bearer`) do NetBox. Respostas
malformadas, versões não informadas, paginação além do limite, schema de escrita
indisponível ou payload fora das restrições falham antes da mutação. URLs são
HTTPS por padrão; HTTP só é permitido quando habilitado explicitamente ou para
o endpoint interno exato do sandbox. Redirects são desativados.
