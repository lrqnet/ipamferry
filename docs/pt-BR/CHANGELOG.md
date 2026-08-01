# Changelog

> **Idioma:** [English](../../CHANGELOG.md) · [Português (Brasil)](CHANGELOG.md) · [Español](../es/CHANGELOG.md)

Todas as mudanças relevantes deste projeto são documentadas neste arquivo.

## [0.3.1] - 2026-08-01

### Corrigido

- O checksum da release agora verifica diretamente o artefato `compose.yaml` baixado.

## [0.3.0] - 2026-08-01

### Adicionado

- Rodapé global responsivo com repositório do projeto, autor, link para GitHub Sponsors, versão instalada e controles de atualização exclusivos do owner.
- Verificação diária e privativa de releases estáveis, além de fluxo seguro de atualização no painel com checksum verificado e Compose fixado por digest.
- Serviço updater dedicado com privilégio mínimo, estado persistente, proteção contra atualizações concorrentes, bloqueio durante migrações e relatório de falha de health check.

### Corrigido

- Marca do cabeçalho e seletor de idioma passam a usar toda a largura disponível, sem ficarem juntos em telas estreitas.
- O updater usa um volume privado dedicado para funcionar corretamente entre containers Laravel não-root no Docker Desktop e hosts Linux.

## [0.2.0] - 2026-07-28

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
