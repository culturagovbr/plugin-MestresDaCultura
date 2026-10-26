<?php
/**
 * Cadastro do programa fora do ar (Controller::GET_index): período fechado, oportunidade não publicada ou não
 * configurada. O motivo fica no log; a mestra vê só o aviso e o contato do suporte. Marcação da página de erro do BaseV2.
 *
 * @var \MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->layout = 'default';

$this->import('
    mc-link
');
?>
<div class="error-page mdc-unavailable">
    <div class="error-card">
        <div class="content">
            <div class="left">
                <h1 class="left__title"><?= i::__('Cadastro indisponível') ?></h1>
                <div class="error-footer">
                    <div class="message-error">
                        <p class="text"><?= i::__('O cadastro na Rede de Mestras e Mestres não está aberto neste momento. Tente novamente mais tarde.') ?></p>
                        <p class="text"><?= sprintf(i::__('Se precisar de ajuda, escreva para %s.'), '<a href="mailto:suporte.mapa@cultura.gov.br">suporte.mapa@cultura.gov.br</a>') ?></p>
                    </div>
                    <div class="btn">
                        <mc-link route="panel/index" class="button button--primary btn-error"><?= i::__('Voltar ao Painel de controle') ?></mc-link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
