# Componente `<mdc-master-card>`

Cartão de mestra ou mestre aprovado: foto, nome artístico, atividades e cidade, o resumo e o link do perfil. Usado na vitrine da home (tema, `home-mestres`) e na busca de Mestras e Mestres (`mdc-masters-search`). O visual fica no tema MestresDaCultura (`_home-mestres.scss`).

## Propriedades

- *Object **master*** - `{name, knownAs, activities, city, summary, avatar, url}`; `activities` em lista ou já em texto.
- *String **headingTag*** - nível do título com o nome: `h3` (padrão, na home) ou `h2` (na busca).

### Usando o componente

```HTML
<mdc-master-card :master="master"></mdc-master-card>
```
