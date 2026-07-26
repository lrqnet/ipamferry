# ADR-002 — Mapping v2 e plano v3

- Estado: aceito
- Data: 2026-07-25

## Contexto

O mapeamento v1 cobria o núcleo de IPAM, mas não expressava de forma segura
políticas por objeto, referências portáveis, status, atualizações e relações
posteriores necessárias para Tenancy, DCIM, Circuits, ASN e NAT.

## Decisão

Adotar:

- schema de mapeamento v2 com regras identificadas e canonicalizadas;
- Mapping Studio visual com JSON Expert sincronizado;
- sugestões determinísticas que exigem aceite;
- preview temporário executado pelo mesmo planejador;
- concorrência otimista por `mapping_revision`;
- plano v3 com ações de objeto e de relação, referências adiadas, checkpoints
  e verificação idempotente;
- registros de recursos e planejadores separados para IPAM, Tenancy, DCIM,
  Circuits e Relations.

Referências usam chaves naturais, nunca IDs numéricos específicos do NetBox.
Mappings v1 continuam verificáveis e só são convertidos quando salvos.

## Alternativas rejeitadas

- Autosave da política publicada.
- Expressões PHP ou JavaScript para transformação.
- Guardar IDs do sandbox no mapping.
- Converter parcialmente PAT, sessões BGP ou circuitos ambíguos.
- Um único planejador monolítico para todos os domínios.

## Consequências

O mapping pode produzir planos irmãos portáveis entre destinos, as decisões
ficam auditáveis e relações posteriores podem ser retomadas com segurança. O
editor precisa manter validação, traduções e compatibilidade de schemas, e
objetos auxiliares requerem aprovação explícita.
