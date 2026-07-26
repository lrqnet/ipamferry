# Changelog

> **Idioma:** [English](../../CHANGELOG.md) · [Português (Brasil)](CHANGELOG.md) · [Español](../es/CHANGELOG.md)

Todas as mudanças relevantes deste projeto são documentadas neste arquivo.

## [0.2.0] - Não publicado

### Adicionado

- Mapping Studio visual com sugestões determinísticas, catálogo sanitizado, preview assíncrono, JSON Expert, undo/redo e concorrência otimista.
- Schema de mapping v2 e plano v3 com ações de objeto/relação, referências adiadas e canonicalização estável.
- Cobertura segura de recursos core do NetBox para tenancy, DCIM, circuits e IPAM.
- Comando interativo de recuperação de senha para administrador do host, com invalidação de sessões e evento de segurança.
- Documentação pública com inglês principal e espelhos em português do Brasil e espanhol.

### Alterado

- Rotas Fortify de reset de senha por email foram desativadas; recuperação não depende de email.
- Objetos auxiliares ausentes exigem aprovação explícita antes de entrar no plano.

### Corrigido

- Mapping Studio apresenta somente o seletor global de idioma; idioma do relatório e bundle permanece uma configuração do projeto.

## [0.1.0] - 2026-07-24

### Adicionado

- Fundação Laravel/Inertia para o workbench de migração phpIPAM para NetBox.
- Stack Compose segura, discovery API/dump, planejamento, bundles auditáveis e sandbox NetBox.
