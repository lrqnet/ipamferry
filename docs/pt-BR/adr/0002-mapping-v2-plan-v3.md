# ADR-0002: Mapping v2 e plan v3

> **Idioma:** [English](../../adr/0002-mapping-v2-plan-v3.md) · [Português (Brasil)](0002-mapping-v2-plan-v3.md) · [Español](../../es/adr/0002-mapping-v2-plan-v3.md)

## Contexto

Um editor JSON isolado era difícil de revisar, e o planejador original não representava relações adiadas seguras entre DCIM, circuits e IPAM.

## Decisão

Adotar Mapping Studio com editor visual e JSON Expert sincronizado. Mapping v2 usa IDs de regras canônicos e estáveis e chaves naturais. Plan v3 separa ações de objeto e relação, inclui referências adiadas e checkpoints e preserva verificação histórica v1/v2.

## Consequências

Operadores recebem fluxo de política revisável, enquanto schemas legíveis por máquina permanecem em inglês e estáveis. Sugestões, updates e criação de recursos auxiliares exigem aprovação explícita.
