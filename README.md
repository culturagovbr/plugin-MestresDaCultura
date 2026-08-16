# plugin-MDC

Plugin da Rede Nacional de Mestras e Mestres das Culturas Tradicionais e Populares para o Mapas Culturais. Reúne as funcionalidades do programa; os aspectos visuais ficam no tema `MestresDaCultura`.

## Namespace

O diretório e o namespace são `MDC`, e não `MestresDaCultura`.

O autoloader do projeto mantém um mapa plano de namespace para diretório, no qual os temas são registrados antes dos plugins. Se os dois usassem o mesmo nome, o diretório do plugin nunca seria registrado e o plugin seria ignorado sem erro, sem aviso e sem log.

Pelo mesmo mecanismo, a resolução substitui todas as ocorrências do namespace no nome da classe — então nenhuma classe, subpasta ou sub-namespace daqui pode conter `MDC` no nome, sob pena de gerar um caminho corrompido.

## Habilitar

Adicione `MDC` à lista `plugins` da configuração.
