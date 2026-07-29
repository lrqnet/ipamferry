# Validação de lançamento

> **Idioma:** [English](../VALIDATION.md) · [Português (Brasil)](VALIDATION.md) · [Español](../es/VALIDATION.md)

O IpamFerry mantém um laboratório local descartável para validar uma origem phpIPAM real, um `mysqldump` real e o caminho da API REST do NetBox. Ele nunca reutiliza a instalação, volumes ou credenciais de um operador.

## Execução local

```bash
./scripts/lab-test.sh v1.8.1
```

O laboratório cria credenciais temporárias em volumes Docker, expõe apenas interfaces de teste em loopback, valida a API phpIPAM de somente leitura, cria um `mysqldump --single-transaction` no diretório ignorado `tmp/lab/` e remove volumes e arquivos gerados ao terminar.

## Dumps externos anonimizados e protegidos

Um dump anonimizado aprovado pode ser validado contra um sandbox NetBox descartável com um arquivo de mapeamento explícito:

```bash
./scripts/lab-external-dump.sh /caminho-seguro/origem.sql /caminho-seguro/mapeamento.json
```

O comando monta os dois arquivos como somente leitura, interpreta o dump apenas como dados, cria e verifica um plano aprovado pela API do NetBox, retoma checkpoints de uma ação, repete a aplicação para provar idempotência, inspeciona o bundle por campos sensíveis e destrói volumes, temporários e bundle ao sair. Ele não se conecta à instância phpIPAM original.

O GitHub Actions oferece o mesmo caminho apenas pelo ambiente protegido `external-corpus-validation` e workflow manual. Os secrets `IPAMFERRY_EXTERNAL_DUMP_B64` e `IPAMFERRY_EXTERNAL_MAPPING_B64` devem conter corpus anonimizado e mapeamento aprovados. Aplicam-se os limites de tamanho dos secrets do GitHub; use o comando local ou runner privado aprovado para corpus maiores. O workflow não publica dump, mapeamento, bundle, trace, screenshot ou log de container.

## Matriz de compatibilidade

As tags e digests imutáveis estão em [`tests/lab/compatibility-manifest.json`](../../tests/lab/compatibility-manifest.json). A validação exige phpIPAM 1.5.2, 1.7.4 e 1.8.1 contra NetBox 4.6.1 em jornada profunda, e phpIPAM 1.8.1 contra NetBox 4.4.10 e 4.5.10 em smoke.

## Cobertura e exclusões

[`tests/lab/coverage-manifest.json`](../../tests/lab/coverage-manifest.json) classifica cada tabela conhecida como `migrated`, `preserved`, `sensitive_excluded`, `unsupported` ou `not_available_in_version`. Credenciais, usuários, sessões, cofres, aplicações API e configurações de agentes de scan nunca entram em planos, bundles, relatórios, artefatos de CI ou documentação pública.

[`tests/lab/scenario-matrix.json`](../../tests/lab/scenario-matrix.json) define os casos de aceite no nível dos dados: limites de CIDR, canonicalização IPv6, identidades de VRF/VLAN, hierarquia, limites de texto, custom fields, tenancy, DCIM, circuitos, NAT, drift do destino, recuperação e sanitização. Cada caso declara se deve migrar, ser preservado, ser bloqueado para revisão ou ser rejeitado com segurança.

Dumps brutos, bancos, bundles gerados, traces do Playwright, screenshots e logs de containers não sanitizados não são retidos como artefatos de CI.

## Status da validação local

A matriz é um gate ativo de release: um cenário só está concluído quando sua evidência executável passar em todas as combinações de compatibilidade exigidas. Em 26-07-2026, todas as cinco combinações de compatibilidade listadas passaram usando tanto a API phpIPAM somente leitura quanto um `mysqldump` real. Cada execução aplicou, verificou e reaplicou com segurança 76 ações centrais e 109 ações ampliadas aprovadas, sem duplicação. A jornada profunda com NetBox 4.6.1 também retomou a mesma execução real por 76 checkpoints persistidos de uma ação. O corpus cria e lê de volta custom fields aprovados do NetBox e seus conjuntos de escolhas, preserva variantes inseguras de NAT e hostnames DNS inválidos e confirma que eles não são enviados ao NetBox.
