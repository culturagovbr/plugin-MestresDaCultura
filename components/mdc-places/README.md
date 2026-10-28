# Componente `<mdc-places>`

A lista de "Meus locais" (`/meus-locais/`): os locais que a pessoa criou na agenda dos festejos, com o endereço, a situação e os festejos que os usam.

- A lista vem do servidor (`MDC\Services\FestivityPlace::ofUser`, por `$MAPAS.config.mdcPlaces`): só os locais da pessoa no subsite, fora da lixeira.
- "Cadastrar local" abre a mesma janela vazia: o local nasce em rascunho, sem festejo, e já aparece na escolha de local da etapa 2.
- "Editar" abre a janela do local (`mdc-place-editor`): o nome e o endereço, o mesmo formulário do local novo da etapa 2.
- "Excluir" só aparece para o local que nenhum festejo usa; o servidor também barra, e a mensagem diz quais festejos o usam.
- A situação (Rascunho ou Publicado) segue o festejo e não se muda aqui (`FestivityPlace::syncStatus`).
