# plugin-MDC

Plugin da Rede Nacional de Mestras e Mestres das Culturas Tradicionais e Populares para o Mapas Culturais. Reúne as funcionalidades do programa; os aspectos visuais ficam no tema `MestresDaCultura`.

## Namespace

O diretório e o namespace são `MDC`, e não `MestresDaCultura`.

O autoloader do projeto mantém um mapa plano de namespace para diretório, no qual os temas são registrados antes dos plugins. Se os dois usassem o mesmo nome, o diretório do plugin nunca seria registrado e o plugin seria ignorado sem erro, sem aviso e sem log.

Pelo mesmo mecanismo, a resolução substitui todas as ocorrências do namespace no nome da classe — então nenhuma classe, subpasta ou sub-namespace daqui pode conter `MDC` no nome, sob pena de gerar um caminho corrompido.

## Habilitar

Adicione `MDC` à lista `plugins` da configuração.

## Componentes

Os componentes Vue ficam em `components/` e o visual deles fica no tema `MestresDaCultura`. A exceção é a ficha da inscrição, uma tela do BaseV1 onde o CSS do tema não chega: o estilo dela fica aqui, em `assets-src/sass/` (compilado com `pnpm run build` em `assets/css/plugin-MDC.css`).

A pasta `assets/` precisa existir mesmo que fique vazia: o core acha o `script.js` de um componente como `assets/../components/<nome>/script.js`, e sem ela as páginas que usam o componente quebram com `Asset not found`.

## Paliativos

- **Fotos e vídeos do agente na ficha do v1** (`Services/AgentMediaView.php`): o `registration-field-view.php` do core não trata os campos `@gallery` e `@videos` e os mostra como `[object Object]`. Na oportunidade do programa, o plugin entrega esses campos já montados em HTML, e a foto clicada abre no `<mdc-photo-viewer>`, na página de cima (`assets-src/js/plugin-MDC.js`). Retirar quando o core os exibir.
