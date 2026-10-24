# plugin-MDC

Plugin da Rede Nacional de Mestras e Mestres das Culturas Tradicionais e Populares para o Mapas Culturais. Reúne as funcionalidades do programa; os aspectos visuais ficam no tema `MestresDaCultura`.

## Namespace

O diretório e o namespace são `MDC`, e não `MestresDaCultura`.

O autoloader do projeto mantém um mapa plano de namespace para diretório, no qual os temas são registrados antes dos plugins. Se os dois usassem o mesmo nome, o diretório do plugin nunca seria registrado e o plugin seria ignorado sem erro, sem aviso e sem log.

Pelo mesmo mecanismo, a resolução substitui todas as ocorrências do namespace no nome da classe — então nenhuma classe, subpasta ou sub-namespace daqui pode conter `MDC` no nome, sob pena de gerar um caminho corrompido.

## Habilitar

Adicione `MDC` à lista `plugins` da configuração e defina no ambiente `MDC_OPPORTUNITY_ID`, o id da oportunidade do programa, e `MDC_SEAL_ID`, o id do selo "Mestra e Mestre da Cultura", que o gestor cria com o texto do certificado (sem padrão: os ids mudam por ambiente). As chaves são lidas pelo plugin, e não pelo tema, porque os jobs rodam pela linha de comando como site principal. A oportunidade do programa precisa estar no subsite dos Mestres: é o subsite dela que diz para onde vão as páginas do programa abertas em outro site.

## Componentes

Os componentes Vue ficam em `components/` e o visual deles fica no tema `MestresDaCultura`. A exceção é a ficha da inscrição, uma tela do BaseV1 onde o CSS do tema não chega: o estilo dela fica aqui, em `assets-src/sass/` (compilado com `pnpm run build` em `assets/css/plugin-MDC.css`).

As bibliotecas de que um componente precisa vão em `dependencies` no `package.json` e são expostas como global por um arquivo de `assets-src/js`, como no módulo Components do core: a curadoria usa partes do d3 e os ajudantes de texto da árvore (`assets-src/js/tree-curation.js`, globais `TreeMapD3` e `MdcTreeText`), carregados só na página da curadoria. O compilado (`assets/` e `mix-manifest.json`) não é versionado: a imagem o gera com `pnpm run build` (`docker/Dockerfile`), e no dev o container compila ao subir.

A pasta `assets/` precisa existir mesmo que fique vazia: o core acha o `script.js` de um componente como `assets/../components/<nome>/script.js`, e sem ela as páginas que usam o componente quebram com `Asset not found`.

## Curadoria da Árvore de Atuação

Página `painel/mdc-tree-curation`, só para administradores do subsite do programa.

- **Regras** em serviços: `TreeCuration` (adicionar, renomear, reordenar, mover, desativar, reativar), `TreeEquivalence` (equivalências) e `OtherQueue` (fila "Não encontrou?"). Regra quebrada lança `Exceptions\CurationRefused`, com a mensagem que a tela mostra.
- **Rotas** no controller `mdc`: `GET curationTree`, `curationOthers` e `curationExport`; `POST curationAdd`, `curationRename`, `curationReorder`, `curationMove`, `curationDisable`, `curationEnable`, `curationEquivalence`, `curationEquivalenceUndo`, `curationOtherCreate`, `curationOtherAssociate` e `curationOtherArchive`.
- **Tela:** `mdc-tree-curation` (página e abas), `mdc-tree-map`, `mdc-tree-search`, `mdc-tree-node-panel` e `mdc-tree-others`; visual no tema.
- **Tabelas:** `mdc_tree_node` (itens, com revisões do core), `mdc_tree_equivalence` e `mdc_tree_other_review`, todas criadas por db-update. Nenhuma ação da curadoria altera as respostas dos cadastros.
- **Implantação:** os db-updates criam as tabelas; quando a entidade `Node` ganha método, as classes de proxy do Doctrine precisam ser geradas de novo (`scripts/generate-proxies.sh`).

## Paliativos

- **Fotos e vídeos do agente na ficha do v1** (`Services/AgentMediaView.php`): o `registration-field-view.php` do core não trata os campos `@gallery` e `@videos` e os mostra como `[object Object]`. Na oportunidade do programa, o plugin entrega esses campos já montados em HTML, e a foto clicada abre no `<mdc-photo-viewer>`, na página de cima (`assets-src/js/plugin-MDC.js`). Retirar quando o core os exibir.
- **Planilha de inscrições** (`Services/RegistrationsSpreadsheet.php`): o job de exportação junta valores em lista com vírgula e apaga quebras de linha. A atuação sai como caminhos separados por " | ". Na oportunidade do programa: fotos, vídeos e links saem como "nome: endereço" e caixas de marcar como "Sim"/"Não"; a consulta sempre pede o status (sem ele, os campos do agente saem do perfil atual, e não do que foi enviado) e os dados dos agentes (sem eles, o responsável sai em branco); e as colunas que nunca têm resposta (seções do formulário e nome do projeto) saem do arquivo.
