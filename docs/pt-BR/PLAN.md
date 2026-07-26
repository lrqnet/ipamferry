# Plano histórico

> **Idioma:** [English](../PLAN.md) · [Português (Brasil)](PLAN.md) · [Español](../es/PLAN.md)

Este documento registra a direção de produto adotada para o IpamFerry 0.2. Não é um plano de migração executável; o comportamento atual é definido pela aplicação, testes, [arquitetura](ARCHITECTURE.md) e ADRs.

## Direção

O painel é a interface principal e os comandos Artisan são a interface de automação. A migração é sempre feita pela API do NetBox. A entrada phpIPAM é sua API oficial ou um `mysqldump` analisado em modo leitura; nenhum dump é executado.

## Escopo de entrega

- instalação Compose segura com PostgreSQL isolado e sandbox opcional;
- discovery de origem/destino, Mapping Studio, preview, planos imutáveis, apply com checkpoints, verificação e bundle auditável;
- cobertura segura de recursos core do NetBox e relatório de preservação para dados não suportados;
- documentação pública em inglês com espelhos completos em português do Brasil e espanhol; e
- recuperação de senha CLI por administrador do host, sem dependência de email.

## Exclusões explícitas

Plugins, conversão de DNS autoritativo, conversão de sessões BGP, PAT, expressões executáveis arbitrárias de mapeamento, escrita direta no banco NetBox, e aceitação automática de sugestões ficam fora de escopo.
