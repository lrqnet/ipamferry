# ADR-0001: Planos imutáveis e aplicação somente por API

> **Idioma:** [English](../../adr/0001-immutable-plans-api-only.md) · [Português (Brasil)](0001-immutable-plans-api-only.md) · [Español](../../es/adr/0001-immutable-plans-api-only.md)

## Contexto

Migração IPAM é destrutiva se origem, destino ou mapeamento mudar entre revisão e execução. Escrita direta no banco NetBox ignora validação, permissões e compatibilidade de API.

## Decisão

Planos são artefatos imutáveis com fingerprint, vinculados a snapshots de origem e destino, mapeamento, locale e versões de API. Apply e verificação usam somente a API REST NetBox com checkpoints persistentes e idempotência por chave natural.

## Consequências

Cada destino exige seu próprio plano aprovado. O sistema pode parar e retomar com segurança, mas não pode aplicar silenciosamente um plano em outro destino ou inventário alterado.
