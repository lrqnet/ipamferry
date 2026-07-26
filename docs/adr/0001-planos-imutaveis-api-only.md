# ADR-001 — Planos imutáveis e aplicação somente por API

- Estado: aceito
- Data: 2026-07-25

## Contexto

Uma migração pode gerar arquivos importáveis ou um banco PostgreSQL previamente
carregado, mas esses caminhos contornariam validações de modelos, permissões,
changelog e compatibilidade interna do NetBox.

## Decisão

O IpamFerry gera planos imutáveis vinculados aos fingerprints de origem,
destino, mapeamento, locale, instância e versão do NetBox. Toda criação,
atualização e relação é aplicada pela API REST oficial. Sandbox e produção usam
planos irmãos separados.

## Alternativas rejeitadas

- Escrever diretamente no PostgreSQL do NetBox.
- Gerar um dump PostgreSQL pronto para restauração.
- Reutilizar literalmente um plano do sandbox em produção.

## Consequências

As migrações respeitam a camada de aplicação do NetBox e podem verificar cada
checkpoint. Em troca, a execução depende da API suportada e cada destino exige
descoberta e planejamento próprios.
