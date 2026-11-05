<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\i;

/**
 * O título da aba das telas do subsite dos Mestres que não têm título próprio, no formato "Tela - nome do site".
 */
class PageTitles
{
    /**
     * O título da tela, ou null para manter o do core.
     *
     * @param array<int|string,mixed> $data os parâmetros da tela
     */
    static function of(string $controller, string $action, array $data = []): ?string
    {
        $title = match ("{$controller}.{$action}") {
            'mdc.masters' => i::__('Mestras e Mestres'),
            'mdc.festivities' => i::__('Festejos Populares'),
            'mdc.festivity' => i::__('Cadastrar festejo'),
            'mdc.editFestivity' => i::__('Editar festejo'),
            'mdc.places' => i::__('Meus locais'),
            'panel.events' => i::__('Meus festejos'),
            'panel.my-account' => i::__('Conta e Privacidade'),
            'seal.sealRelation' => i::__('Certificado'),
            'auth.index' => i::__('Entrar'),
            'faq.index' => i::__('Dúvidas frequentes'),
            'lgpd.view' => match ($data[0] ?? null) {
                'termsOfUsage' => i::__('Termos e Condições de Uso'),
                'privacyPolicy' => i::__('Política de Privacidade'),
                'termsUse' => i::__('Autorização de Uso de Imagem'),
                default => null,
            },
            'registration.single', 'registration.view' => ProgramVocabulary::appliesTo(App::i()->user) ? i::__('Ficha do cadastro') : i::__('Inscrição'),
            'registration.edit', 'registration.registrationEdit' => ProgramVocabulary::appliesTo(App::i()->user) ? i::__('Formulário de cadastro') : i::__('Inscrição'),
            'registration.evaluation' => ProgramVocabulary::appliesTo(App::i()->user) ? i::__('Moderação do cadastro') : i::__('Avaliação'),
            'panel.mdc-tree-curation' => i::__('Curadoria da Árvore de Atuação'),
            default => null,
        };

        return $title === null ? null : "{$title} - " . App::i()->siteName;
    }
}
