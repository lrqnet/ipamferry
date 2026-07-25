# Plano histórico do IpamFerry

> [!NOTE]
> Este documento registra a exploração inicial do produto. A implementação
> atual adotou a decisão posterior de usar Laravel 13, PHP 8.4, Inertia/React,
> PostgreSQL e Docker Compose no padrão do NetKeep. Consulte
> [ARQUITETURA.md](ARQUITETURA.md) para as decisões vigentes.

## 1. Visão do produto

O IpamFerry será um migrador auditável de phpIPAM para NetBox. A primeira
versão será uma CLI distribuída como pacote Python e imagem Docker. O núcleo
de migração não dependerá da interface, permitindo adicionar uma TUI
posteriormente sem reimplementar as regras de negócio.

Princípios:

- phpIPAM é sempre uma origem somente leitura;
- nenhuma alteração é feita no NetBox sem um plano prévio;
- uma execução repetida não deve criar objetos duplicados;
- toda transformação deve ser explicável e auditável;
- credenciais nunca são salvas nos arquivos de plano, estado ou log;
- diferenças entre os modelos do phpIPAM e do NetBox são tratadas
  explicitamente, nunca por conversão direta de JSON.

## 2. Decisões iniciais

| Tema | Decisão |
| --- | --- |
| Linguagem | Python 3.12+ |
| Interface principal | CLI com Typer e Rich |
| Distribuição | `pipx` e imagem OCI/Docker no GHCR |
| Comunicação | APIs REST do phpIPAM e do NetBox |
| Configuração | YAML versionado por schema |
| Validação | Pydantic |
| Cliente HTTP | HTTPX |
| Estado local | SQLite |
| Plano exportável | JSON, com resumo legível no terminal |
| Licença | AGPL-3.0-only |
| Interface interativa | TUI em uma fase posterior |
| Interface web | Fora do escopo inicial |

Usar SQLite para checkpoints não transforma a ferramenta em um serviço: ele
é apenas um artefato local, montável como volume no Docker, com relações entre
IDs, hashes dos objetos e andamento da execução.

## 3. Escopo do MVP

### Incluído

1. Conectar e validar versões e permissões das duas APIs.
2. Descobrir o inventário e os campos personalizados do phpIPAM.
3. Gerar uma configuração inicial de mapeamento.
4. Extrair Sections, VRFs, VLANs, Subnets e IP Addresses.
5. Normalizar os dados em um modelo intermediário independente das APIs.
6. Validar referências, sobreposições, campos obrigatórios e conflitos.
7. Consultar o NetBox e classificar cada ação como:
   - criar;
   - reutilizar;
   - atualizar, quando autorizado;
   - ignorar;
   - conflito impeditivo.
8. Exportar um plano imutável com resumo e avisos.
9. Aplicar somente um plano válido e aprovado explicitamente.
10. Retomar uma execução interrompida.
11. Verificar contagens, relações e campos essenciais após a aplicação.

### Adiado

- modelagem completa de devices, racks e interfaces;
- permissões de usuários e grupos;
- NAT, circuitos, cabos e dados de descoberta;
- sincronização contínua ou bidirecional;
- rollback destrutivo automático;
- criação automática de tipos arbitrários de custom fields;
- interface web.

Devices ficam fora do MVP porque o objeto simples do phpIPAM não possui
informação suficiente para satisfazer naturalmente o modelo DCIM do NetBox.
Eles podem ser incluídos quando houver uma estratégia explícita para site,
role, manufacturer, device type e interfaces.

## 4. Mapeamento conceitual inicial

| phpIPAM | NetBox | Estratégia padrão |
| --- | --- | --- |
| Section | Tag | Preservar agrupamento sem inventar hierarquia organizacional |
| VRF | VRF | Correspondência direta por RD ou nome |
| L2 Domain | VLAN Group | Opcional e configurável |
| VLAN | VLAN | Criar ou reutilizar por VID e escopo |
| Folder | Prefix pai ou ignorar | Exigir regra configurada |
| Subnet | Prefix | Preservar hierarquia, VRF, VLAN, status e descrição |
| IP Address | IP Address | Preservar endereço, status, DNS name e descrição |
| Address hostname | `dns_name` | Normalizar e validar |
| Tags/status | Status ou Tag | Tabela de tradução configurável |
| Custom field | Campo nativo, custom field ou ignorar | Regra explícita por campo |

O padrão para Section deve ser conservador. Transformá-la automaticamente em
Tenant, Site ou Region alteraria o significado dos dados. O usuário poderá
substituir a estratégia no arquivo de mapeamento.

## 5. Fluxo de uso

```text
doctor -> inspect -> init -> plan -> apply -> verify
```

Comandos propostos:

```bash
ipamferry doctor
ipamferry inspect --output inventory.json
ipamferry init --output ipamferry.yaml
ipamferry plan --config ipamferry.yaml --output migration.plan.json
ipamferry apply migration.plan.json
ipamferry verify migration.plan.json
ipamferry status
```

- `doctor`: testa URLs, TLS, tokens, versões e permissões mínimas.
- `inspect`: lê a origem e resume tipos, quantidades e custom fields.
- `init`: gera um YAML comentado a partir da descoberta.
- `plan`: não escreve no NetBox; resolve dependências e conflitos.
- `apply`: exige o arquivo de plano e confirmação, ou `--yes` em automação.
- `verify`: compara a intenção do plano com o estado final.
- `status`: mostra checkpoints de uma aplicação interrompida.

O plano deve conter a versão do schema, versões detectadas dos sistemas,
timestamp, fingerprint da configuração, ações ordenadas e hashes dos dados de
origem. Tokens e segredos são proibidos nesse arquivo.

## 6. Arquitetura

```text
src/ipamferry/
├── cli/                 # comandos e apresentação
├── config/              # schema e carregamento do YAML
├── sources/phpipam/     # cliente e tradução da origem
├── targets/netbox/      # cliente, descoberta e escrita
├── domain/              # modelo intermediário
├── mapping/             # regras e transformações
├── planning/            # diff, conflitos e dependências
├── execution/           # aplicação, checkpoint e retomada
├── verification/        # verificações pós-migração
└── reporting/           # terminal e JSON
```

O domínio não importa classes de HTTP, CLI, phpIPAM ou NetBox. Adaptadores
convertem respostas externas para o domínio e convertem ações planejadas para
requisições do NetBox.

Pipeline:

```text
extract -> normalize -> map -> validate -> diff -> plan -> apply -> verify
```

## 7. Idempotência e identificação

Cada objeto normalizado terá:

- `source_type`;
- `source_id`;
- `natural_key`;
- representação canônica;
- hash estável.

O SQLite relacionará `(source_instance, source_type, source_id)` ao tipo e ID
do objeto no NetBox. Antes de criar, o planejador procura:

1. uma relação já registrada;
2. uma chave natural inequívoca no NetBox;
3. um marcador de origem configurado;
4. caso contrário, propõe criação.

Resultados ambíguos viram conflito; o IpamFerry não escolhe silenciosamente.
Atualizações serão opt-in por tipo de objeto e campo.

## 8. Ordem de dependências

Ordem inicial de aplicação:

1. tags e outros metadados permitidos;
2. VRFs e VLAN Groups;
3. VLANs;
4. prefixes pais;
5. prefixes filhos;
6. IP addresses;
7. relações e campos que dependam dos IDs criados.

O planejador representa isso como um grafo acíclico. Referências ausentes,
ciclos e hierarquias inconsistentes impedem a aplicação.

## 9. Configuração

Estrutura conceitual:

```yaml
schema_version: 1

source:
  url: https://phpipam.example
  app_id: ipamferry
  token_env: PHPIPAM_TOKEN
  verify_tls: true

target:
  url: https://netbox.example
  token_env: NETBOX_TOKEN
  verify_tls: true

policies:
  existing_objects: reuse
  updates: deny
  unknown_fields: warn

mapping:
  sections:
    strategy: tag
  statuses:
    Used: active
    Reserved: reserved
  custom_fields:
    subnet:
      customer:
        target: custom_fields.customer
      legacy_note:
        action: ignore
```

O YAML referencia variáveis de ambiente; não contém os valores dos tokens.
Cada campo descoberto precisa ter destino, transformação ou `ignore`
explícito antes de um plano ser considerado limpo.

## 10. Segurança

- exigir HTTPS por padrão;
- permitir desativar validação TLS somente com flag explícita e aviso;
- mascarar headers, tokens e valores sensíveis em logs e exceções;
- limitar retries a operações comprovadamente seguras;
- usar timeouts em todas as requisições;
- não executar código ou templates arbitrários vindos do YAML;
- registrar um `changelog_message` do IpamFerry quando suportado;
- recomendar token somente leitura no phpIPAM e token mínimo no NetBox.

## 11. Testes e qualidade

Pirâmide de testes:

1. unitários para normalização, mapeamento e chaves naturais;
2. contratos das APIs usando respostas gravadas e sanitizadas;
3. integração contra containers efêmeros, quando viável;
4. cenários end-to-end pequenos e determinísticos;
5. testes de repetição: segunda aplicação deve produzir zero criações;
6. testes de interrupção e retomada;
7. testes que garantam ausência de segredos em logs e planos.

Ferramentas iniciais:

- pytest;
- respx;
- Ruff;
- mypy;
- coverage;
- pre-commit;
- GitHub Actions.

## 12. Fases de implementação

### Fase 0 — Fundação

- manifesto `pyproject.toml`;
- pacote `src/`;
- CLI mínima;
- lint, tipos e testes no CI;
- LICENSE, README, CONTRIBUTING, SECURITY e Code of Conduct;
- publicação de imagem de desenvolvimento.

Critério de saída: `ipamferry --help`, testes e build funcionam em ambiente
limpo.

### Fase 1 — Descoberta somente leitura

- clientes das duas APIs;
- `doctor`;
- `inspect`;
- detecção de capacidades e versões;
- inventário sanitizado em JSON.

Critério de saída: obter contagens e campos sem alterar nenhum sistema.

### Fase 2 — Modelo e planejamento

- modelo intermediário;
- schema YAML;
- `init`;
- normalização e mapeamento do escopo MVP;
- validações e grafo de dependências;
- `plan`.

Critério de saída: fixture representativa produz sempre o mesmo plano e todos
os conflitos aparecem antes da escrita.

### Fase 3 — Aplicação e verificação

- executor;
- SQLite e checkpoints;
- idempotência;
- `apply`, `status` e `verify`;
- relatórios finais.

Critério de saída: cenário end-to-end pode ser interrompido, retomado e
executado novamente sem duplicação.

### Fase 4 — Distribuição pública

- pacote Python;
- imagem multiarch no GHCR;
- SBOM e scan de vulnerabilidades;
- documentação e exemplos sanitizados;
- matriz de compatibilidade.

Critério de saída: um usuário novo consegue realizar uma migração de teste
seguindo apenas a documentação publicada.

### Fase 5 — Assistente interativo

- TUI baseada no mesmo schema YAML;
- visualização de campos lado a lado;
- resolução guiada de conflitos;
- gravação da configuração, sem execução implícita.

Critério de saída: a TUI gera o mesmo YAML aceito pela CLI e não contém regras
de migração próprias.

## 13. Versionamento e compatibilidade

- SemVer para o aplicativo;
- `schema_version` independente para configuração, plano e estado;
- matriz testada de versões phpIPAM/NetBox;
- falha segura para versões desconhecidas, com opção explícita de continuar
  apenas quando as capacidades necessárias forem detectadas;
- migrações de schema locais antes de quebrar formatos persistidos.

## 14. Entregável da primeira milestone

A primeira milestone pública deve permitir:

1. instalar o IpamFerry;
2. validar as duas conexões;
3. inspecionar uma instância phpIPAM;
4. gerar e editar um arquivo de mapeamento;
5. produzir um plano completo sem escrever no NetBox.

Essa milestone reduz cedo o maior risco do projeto: descobrir como dados reais
do phpIPAM se relacionam com o modelo do NetBox. Escrita e interface
interativa só avançam depois que o planejador estiver confiável.
