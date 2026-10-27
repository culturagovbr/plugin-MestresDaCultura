# Componente `<mdc-festivity-person>`

A pessoa responsável pelo festejo, nas etapas 4 e 5 do cadastro (itens 28 a 37 da planilha V2). Os dados ficam no perfil de quem cadastra (o dono do evento), nos mesmos campos do formulário dos Mestres: quem já é mestra ou mestre só confere.

- `part="contact"`: nome completo, data de nascimento (com a idade, `mdc-age`), e-mail e telefone. O e-mail e o telefone públicos só com o "Aceito que fique disponível ao público", como no formulário dos Mestres; sem ele, o campo público é apagado.
- `part="markers"`: gênero, orientação sexual, raça/cor, deficiência e povo ou comunidade tradicional.
- O core guarda todos como privados, menos o e-mail e o telefone públicos. O CPF não é pedido: vem da criação da conta.
- A pessoa vem do servidor (`MDC\Services\FestivityForm::person`, em `$MAPAS.config.mdcFestivity.person`), e não da API, que lê as permissões do cache. Quem edita o festejo sem poder editar o perfil do dono (o suporte, quem controla o evento) vê só um aviso, e nada do perfil é salvo; a autorização de tornar público só aparece para a própria pessoa.
- O formulário chama `save()` antes de salvar o festejo.

## Propriedades

- *String **part*** - `contact` ou `markers`.
