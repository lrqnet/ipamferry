# Mapping Studio

O Mapping Studio é a etapa visual entre a descoberta e o plano oficial. Ele
edita a política canônica de mapeamento sem executar código e permite revisar
o impacto da migração antes de produzir um plano aprovável.

## Fluxo

1. Descubra novamente a origem e o destino quando os dados mudarem.
2. Abra **Mapping Studio** no projeto.
3. Revise as políticas de objetos e aceite apenas as sugestões desejadas.
4. Resolva referências, campos obrigatórios e relações.
5. Execute o preview até não restarem conflitos impeditivos.
6. Salve explicitamente a política.
7. Volte ao projeto e gere o plano oficial.
8. Depois de verificar um ensaio, atualize o snapshot do NetBox antes de gerar
   um plano irmão; o backend recusa planejar sobre o snapshot antigo.

O preview usa o mesmo planejador do plano oficial, mas é temporário, não pode
ser aprovado nem aplicado e permanece vinculado aos fingerprints da origem,
do destino e à revisão do mapeamento. Uma alteração posterior exige outro
preview.

## Abas

- **Visão geral** mostra revisão, schema, cobertura e sugestões determinísticas.
- **Objetos** decide `migrate`, `ignore` ou `preserve` para cada tipo.
- **Referências** define chaves naturais portáveis entre sandbox e produção.
- **Campos** oferece copiar, ignorar, valor fixo, concatenação, normalização e
  tabela de lookup.
- **Status/updates** converte choices descobertas por `OPTIONS` e autoriza
  campos específicos para `PATCH`.
- **Relações** classifica Locations, define pré-requisitos por categoria e
  exceções individuais de Devices e aprova contatos, ASN/RIR, circuitos,
  primary IP e NAT 1:1.
- **Preview** apresenta ações estimadas, preservação, warnings e conflitos.
- **JSON Expert** edita o mesmo documento canônico com validação por caminho.

Sugestões nunca são publicadas automaticamente. O editor mantém undo/redo
local, avisa ao sair com alterações pendentes e usa `mapping_revision` para
impedir sobrescrita silenciosa entre operadores.

## Catálogo sanitizado

O navegador recebe apenas um catálogo resumido:

- nomes de campos e tipo inferido;
- percentual de preenchimento;
- cardinalidade limitada;
- no máximo cinco exemplos truncados;
- identidades resumidas necessárias às decisões visuais;
- hints limitados de relacionamento, contendo apenas IDs de origem necessários
  para categoria, localização, rack, device, interface, provider e tipo;
- choices e campos de escrita descobertos no NetBox.

Snapshots completos, tokens, campos sensíveis e valores com aparência de
segredo não são enviados à interface. O catálogo é versionado e vinculado aos
fingerprints da origem e do destino.

## Schema de mapeamento v2

O JSON canônico permanece em inglês e contém:

| Seção                | Responsabilidade                                |
| -------------------- | ----------------------------------------------- |
| `object_policies`    | Migrar, ignorar ou preservar cada tipo          |
| `reference_rules`    | Resolver referências por chave natural          |
| `status_rules`       | Converter status para choices válidas do NetBox |
| `update_rules`       | Autorizar campos existentes para `PATCH`        |
| `field_rules`        | Transformar valores sem código executável       |
| `relation_rules`     | Configurar relações posteriores e dependências  |
| `preservation_rules` | Explicar o destino dos dados sem equivalência   |

Cada regra possui ID estável. A canonicalização ordena regras por ID, portanto
reordenar cartões na interface não muda o fingerprint. Mapeamentos v1 continuam
válidos; a conversão para v2 só é persistida quando o usuário salva.

## Equivalências ampliadas

| phpIPAM                      | NetBox ou conduta                                                                                                    |
| ---------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| Customers                    | Tenant; Contact somente com Contact Role aprovado                                                                    |
| Sections e IP Tags           | Tags opt-in e regras de status                                                                                       |
| Locations                    | Site ou Location subordinada a Site                                                                                  |
| Racks                        | Rack com Site/Location resolvido                                                                                     |
| Device Types                 | Device Role, nunca modelo físico                                                                                     |
| Devices                      | Device com Site, Role e Device Type obrigatórios                                                                     |
| Portas e MACs                | Interface e MAC Address quando válidos                                                                               |
| Circuitos                    | Provider, Circuit Type, Circuit e terminações seguras; tipos exigem dump porque a API oficial não expõe essa coleção |
| BGP                          | ASN; sessões permanecem preservadas                                                                                  |
| NAT                          | Somente vínculo IP↔IP estático 1:1 confirmado                                                                        |
| Hostnames                    | `dns_name` do IP Address                                                                                             |
| DNS autoritativo e extensões | Preservação ou custom field aprovado                                                                                 |

Objetos auxiliares ausentes — como Manufacturer, Device Type, Site, Contact
Role e RIR — só entram no plano quando a criação proposta é aprovada.

## Limites de segurança

- Um Device sem Site, Role ou Device Type bloqueia o plano.
- Device Type exige Manufacturer; Rack e Location exigem Site.
- IP de device sem porta permanece sem interface e gera warning.
- MAC inválido ou sem porta é preservado com motivo; repetições válidas na
  mesma porta não criam interfaces ou MACs duplicados.
- Primary IP exige correspondência única com um IP migrado e atribuído.
- PAT, NAT com portas, relações NAT muitos-para-muitos e sessões BGP não são
  convertidos parcialmente.
- Terminações de circuito exigem localização inequívoca; cabos não são
  inventados.
- Tabelas de extensões desconhecidas continuam fora da whitelist. O relatório
  registra a exclusão sem preservar valores potencialmente secretos.

## Plano v3 e ordem

O plano v3 separa ações de objeto e ações de relacionamento, conserva
referências adiadas, checkpoints e verificação idempotente. A ordem é:

```text
custom fields/tags/tenants
→ sites/locations
→ racks
→ manufacturers/device types/roles
→ devices/interfaces/MACs
→ providers/circuits
→ RIRs/ASNs
→ VRFs/VLANs/prefixes/IPs
→ assignments/primary IP/NAT
```

O executor continua usando exclusivamente a API REST do NetBox, com locks,
ETag quando disponível, checkpoints e vínculos persistentes. A verificação
canonicaliza diferenças entre campos REST de escrita e representação, como
`termination_id`/`termination`, listas de objetos e números inteiros
serializados como decimais, sem aceitar valores semanticamente diferentes.

## Compatibilidade

- phpIPAM 1.5 a 1.8;
- NetBox 4.4 a 4.6;
- sandbox fixado em NetBox 4.6.1.

A API phpIPAM cobre somente controllers oficialmente expostos. Recursos
disponíveis apenas no dump aparecem dessa forma na matriz de cobertura.

Veja também [Arquitetura](ARQUITETURA.md) e
[ADR-002](adr/0002-mapping-v2-plan-v3.md).
