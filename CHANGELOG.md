# Changelog

Todas as mudanças relevantes deste projeto serão documentadas aqui.

## [0.2.0] - Unreleased

### Added

- Mapping Studio visual com sugestões determinísticas, catálogo sanitizado,
  preview assíncrono, JSON Expert, undo/redo e concorrência otimista.
- Schema de mapping v2 e plano v3 com ações de objeto/relação, referências
  adiadas e canonicalização por IDs estáveis.
- Planejadores separados de IPAM, Tenancy, DCIM, Circuits e Relations.
- Migração segura de tenants, tags, sites, locations, racks, devices,
  interfaces, MACs, circuitos, RIRs, ASNs, primary IP e NAT estático 1:1.
- Cobertura, referências propostas e decisões de preservação no bundle.
- Documentação e ADRs em português, inglês e espanhol.

### Changed

- Descoberta phpIPAM/NetBox valida a matriz phpIPAM 1.5–1.8 e NetBox 4.4–4.6.
- Objetos auxiliares ausentes exigem aprovação explícita antes de entrar no
  plano.
- PAT, sessões BGP e relações ambíguas são preservados sem conversão parcial.
- Um plano irmão exige atualização explícita do snapshot NetBox depois da
  verificação, evitando planejar sobre o inventário anterior à aplicação.

### Fixed

- Verificação canonicaliza campos REST de escrita/representação e números
  decimais equivalentes retornados pelo NetBox.
- MACs inválidos ou sem porta são preservados com motivo e repetições válidas
  não criam interfaces duplicadas.
- Identidades de Device e Device Type incluem Site e Manufacturer,
  respectivamente, impedindo correspondências entre escopos diferentes.

## [0.1.0] - 2026-07-24

### Added

- Fundação Laravel/Inertia para o workbench de migração phpIPAM → NetBox.
- Compose seguro, API/dump discovery, planejamento, bundle e sandbox NetBox.
- Interface e artefatos em inglês, português do Brasil e espanhol.
- Checkpoints persistentes, vínculos de objetos, auditoria e verificação exata.

### Changed

- Planos agora são imutáveis e específicos de origem, destino, versão de API,
  mapeamento e locale.
- O sandbox usa NetBox 4.6.1 fixado com token v2 idempotente e serviços internos
  sem portas publicadas.
- Atualizações são opt-in e respeitam o schema de escrita descoberto via
  `OPTIONS`.

### Fixed

- Inicialização limpa e upgrades do Compose, incluindo cache de providers,
  ownership do PostgreSQL sandbox e estágio runtime da imagem.
- Upload de `mysqldump` em filesystem read-only, com diretório temporário
  privado e limites PHP coerentes com o limite de 1 GiB da aplicação.
- Limitador de login ausente, acessibilidade do formulário e apresentação de
  erros de validação no wizard.
- Persistência atômica do seletor de idioma e vinculação obrigatória de
  `apply`/`verify` ao destino gravado no plano.
- Janela de saúde do primeiro boot do NetBox sandbox, incluindo o aquecimento
  lento da primeira resposta Django.
- Retomada idempotente após respostas perdidas em POST/PATCH e detecção de
  drift antes de atualizar.
- Colisões de identidades alternativas, limites de campos e consultas
  PostgreSQL de resumos de execução.
