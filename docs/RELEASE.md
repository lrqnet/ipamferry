# Release, GitHub Actions e Docker Hub

## Pré-requisitos externos

Antes da primeira publicação, crie os repositórios públicos `lrqnet/ipamferry`
no Docker Hub e no GitHub. No repositório GitHub, em **Settings → Actions →
General**, permita que `GITHUB_TOKEN` crie e publique pacotes.

Crie estes secrets em **Settings → Secrets and variables → Actions**:

- `DOCKERHUB_USERNAME`: usuário ou conta de automação Docker Hub;
- `DOCKERHUB_TOKEN`: personal access token Docker Hub com permissão de escrita.

O token não entra em `.env`, Compose, imagem, log ou documentação pública.

## Checks contínuos

- `checks`: Composer, Pint, PHPStan, PHPUnit, build/lint/types do frontend,
  build Docker e jornada E2E Chromium em cada PR e push para `main`.
- `e2e-nightly`: Chromium, Firefox e WebKit diariamente e sob demanda.
- `security`: auditoria Composer/npm, Trivy de filesystem, configuração e
  segredos em PR, push e execução semanal.

Defina `checks / quality`, `checks / compose` e `checks / e2e` como required
status checks na branch `main` após o primeiro push bem-sucedido.

## Publicação

As imagens são publicadas em `docker.io/lrqnet/ipamferry` e
`ghcr.io/lrqnet/ipamferry`, para `linux/amd64` e `linux/arm64`. O workflow
`release` é acionado exclusivamente por tag anotada SemVer que aponta para a
`main` remota e corresponde ao `CHANGELOG.md` e à versão do Compose.

```bash
git checkout main
git pull --ff-only
git tag -a v0.1.0 -m 'IpamFerry v0.1.0'
git push origin refs/tags/v0.1.0
```

Ele constrói e publica imagens multiarch, produz SBOM/provenance, assina os
digests com Cosign keyless e anexa à GitHub Release um `compose.yaml` fixado no
digest. Tags versionadas são imutáveis: uma publicação parcial deve ser
corrigida com uma nova versão patch, nunca movendo a tag.
