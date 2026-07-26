# Mapping Studio

> **Idioma:** [English](../MAPPING-STUDIO.md) · [Português (Brasil)](MAPPING-STUDIO.md) · [Español](../es/MAPPING-STUDIO.md)

Mapping Studio é o editor explícito de políticas entre discovery e planejamento formal. Readers podem consultar; operators, administrators e owners podem salvar alterações quando não há lock de execução.

## Espaço de trabalho

O studio fornece Overview, Objects, References, Fields, Status/updates, Relations, Preview e JSON Expert. O editor visual mostra apenas catálogo sanitizado: tipo inferido, taxa de preenchimento, cardinalidade limitada e até cinco exemplos truncados. Snapshots completos, segredos e credenciais nunca chegam ao navegador.

Sugestões são determinísticas, com base em nomes, slugs, tipos e chaves naturais. Nunca são persistidas até o operador aceitá-las e salvá-las explicitamente. Preview executa o mesmo planejador de modo assíncrono, mas não pode ser aprovado ou aplicado.

## Mapping v2

O JSON canônico em inglês guarda IDs de regra estáveis e ordenados para `object_policies`, `reference_rules`, `status_rules`, `update_rules`, `field_rules`, `relation_rules` e `preservation_rules`. Referências usam chaves naturais, nunca IDs numéricos do destino. Mapping v1 permanece legível e só é atualizado quando salvo.

O JSON Expert usa CodeMirror, validação JSON Pointer, controles aplicar/descartar, undo/redo local, aviso de alteração não salva e concorrência otimista por `mapping_revision`.

## Regras de devices e relações

Devices exigem Site, Device Role, Manufacturer e Device Type. Racks e locations exigem Site. Interfaces exigem tipo válido; vínculo IP-interface exige porta. Primary IP, terminações de circuito e NAT estático 1:1 exigem identidades migradas inequívocas. PAT, NAT com porta, NAT muitos-para-muitos, sessões BGP e cabeamento inventado são preservados em vez de transformados parcialmente.
