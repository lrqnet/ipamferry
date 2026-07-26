# Desenvolvimento e teste local

## Docker Compose

O teste integrado requer Docker Engine 25+ e Docker Compose 2.24+. No macOS,
instale e inicie o Docker Desktop antes de continuar:

```bash
brew install --cask docker-desktop
open -a Docker
docker version
docker compose version
```

O daemon precisa estar ativo antes de `docker version` mostrar as seções
`Client` e `Server`. Para subir uma build local sem publicar imagem:

```bash
docker compose -f compose.yaml -f compose.dev.yaml up -d --build --wait
docker compose ps
docker compose exec app php artisan ipamferry:installation-token
```

Abra `https://localhost` e aceite o certificado da CA interna do Caddy apenas
para esse teste. Use o token exibido para criar o primeiro owner. Para parar e
remover os dados de teste:

```bash
docker compose -f compose.yaml -f compose.dev.yaml down --volumes --remove-orphans
```

Para incluir o NetBox descartável fixado e validar todos os serviços:

```bash
docker compose -f compose.yaml -f compose.dev.yaml --profile sandbox up -d --build --wait
docker compose -f compose.yaml -f compose.dev.yaml --profile sandbox ps
```

O primeiro boot do NetBox pode levar de 10 a 15 minutos enquanto aplica suas
migrations e aquece os workers. `--wait` só retorna quando o serviço estiver
saudável. A API e os bancos do sandbox não publicam portas no host.

Comandos Artisan executados por `docker compose exec` carregam os segredos
internos automaticamente. Credenciais externas continuam efêmeras:

```bash
docker compose exec \
  -e NETBOX_URL=https://netbox.example.test \
  -e NETBOX_TOKEN=TOKEN_TEMPORARIO \
  app php artisan ipamferry:verify PROJECT_ID --plan=PLAN_ID
```

## E2E

Os testes Playwright usam portas isoladas e removem seus volumes no final. A
jornada completa também sobe o perfil sandbox:

```bash
npm ci --ignore-scripts
npx playwright install chromium firefox webkit
./scripts/e2e-test.sh chromium
```

Use `./scripts/e2e-test.sh all` para Chromium, Firefox e WebKit. O script
cria uma stack e volumes novos para cada navegador, configura
`IPAMFERRY_BIND_IP=127.0.0.1`, usa `https://localhost:18444` e injeta o token
de instalação somente no processo Playwright.

Durante diagnóstico, `IPAMFERRY_E2E_KEEP_FAILED=1` mantém apenas a stack E2E
isolada quando o navegador falha. `IPAMFERRY_E2E_NETBOX_SEED` pode apontar para
um dump custom do PostgreSQL já migrado e compatível com NetBox 4.6.1; o script
remove todos os tokens NetBox restaurados antes de iniciar o sandbox. O arquivo
é temporário, não deve entrar no repositório e deve ser apagado após os testes.

## Verificações sem Docker

```bash
npm run types:check
npm run build
composer install
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
```
