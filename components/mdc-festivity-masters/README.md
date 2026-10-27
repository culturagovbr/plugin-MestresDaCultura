# Componente `<mdc-festivity-masters>`

Vínculo do festejo com as mestras e os mestres da Rede, na etapa 3 do cadastro (item 17 da planilha V2).

- A busca é a da API de agentes, só com quem tem o selo do programa (`$MAPAS.config.mdcFestivity.sealId`), e por nome também acha o nome artístico, como a busca de Mestras e Mestres.
- Os escolhidos ficam em `mdcFestejoMestres`, como `|12|34|`; o servidor recusa quem não tem o selo (`MDC\Services\FestivityForm::invalid`).
- O vínculo é só do festejo: a relação de agentes do core pediria o aceite de cada mestre, que a planilha não prevê.

## Propriedades

- *Entity **entity*** - o festejo.
