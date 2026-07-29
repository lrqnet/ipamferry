# Arquitetura

> **Idioma:** [English](../ARCHITECTURE.md) · [Português (Brasil)](ARCHITECTURE.md) · [Español](../es/ARCHITECTURE.md)

## Limite do produto

IpamFerry é uma aplicação Laravel self-hosted que migra dados do phpIPAM para o NetBox exclusivamente pela API REST do NetBox. Ela nunca escreve diretamente no banco do NetBox nem executa um dump SQL enviado.

## Runtime

- Laravel 13 / PHP 8.4, Inertia, React, TypeScript e Tailwind;
- PostgreSQL para estado da aplicação, planos, checkpoints, auditoria e jobs;
- FrankenPHP/Caddy como único serviço exposto à LAN;
- serviços dedicados de worker e scheduler; e
- perfil opcional e isolado de sandbox NetBox.

O `init` cria os segredos de instalação sem acesso à rede. PostgreSQL é privado, e a aplicação armazena somente snapshots sanitizados, hashes e referências de credenciais. Tokens de API existem apenas no processo da requisição/job que os utiliza.

## Motor de migração

Discovery cria snapshots versionados da origem e destino. Mapping Studio produz regras canônicas mapping v2. Planning transforma snapshot de origem, snapshot de destino, mapeamento, locale e versões de API em ações imutáveis plan v3 com fingerprint SHA-256. Apply usa checkpoints persistentes, chaves naturais, estado observado e ETag/`If-Match` quando suportado para retomar com segurança sem duplicação.

A ordem dos recursos é: custom fields/tags/tenants; sites/locations; racks; manufacturers/device types/roles; devices/interfaces/MACs; providers/circuits; RIRs/ASNs; VRFs/VLANs/prefixes/IPs; depois atribuições adiadas, primary IPs, terminações e NAT estático.

## Modelo de segurança

Objetos NetBox existentes são reutilizados por padrão. Updates e criações auxiliares são opt-in e exigem aprovação do plano. Entrada incompleta ou ambígua bloqueia aprovação em vez de adivinhar. Dados phpIPAM inseguros ou sem suporte aparecem no relatório de preservação sem valores secretos.

## Identidade e localização

A interface web e os artefatos legíveis suportam inglês, português do Brasil e espanhol. O locale do projeto é guardado no projeto de migração para os relatórios permanecerem consistentes. Saída CLI e schemas JSON legíveis por máquina permanecem em inglês.

Veja [ADR-0001](adr/0001-immutable-plans-api-only.md), [ADR-0002](adr/0002-mapping-v2-plan-v3.md) e [ADR-0003](adr/0003-cli-password-recovery.md).
