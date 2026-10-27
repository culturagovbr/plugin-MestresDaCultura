# Componente `<mdc-festivity-agenda>`

Agenda do festejo na etapa 2 do cadastro (itens 7 e 11 a 13 da planilha V2): os períodos do ano, cada um com o seu local.

- Cada período é uma ocorrência do core (`eventoccurrence`): um dia, ou todos os dias do início ao fim, gravada como o "Criar ocorrência" do core.
- O local é um espaço de quem cadastra: um dos que já tem ou um novo, com o endereço pelo `entity-location` do core (CEP, estado, município e mapa). O espaço não tem página própria (`MDC\Services\FestivityPlace`).
- A UF, a cidade e a região do festejo saem do primeiro local da agenda, no servidor.
- O que falta (sem período, local sem estado ou município) vem dos erros da etapa (`occurrences`, `place`).

## Propriedades

- *Entity **entity*** - o festejo, já salvo.
- *Boolean **required*** - marca "obrigatório".

## Eventos

- **change** - a agenda mudou.
