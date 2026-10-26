<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\i;
use MapasCulturais\UserInterface;
use POMO\Translations\EntryTranslations;
use POMO\Translations\NOOPTranslations;
use POMO\Translations\Translations;

/**
 * "Cadastro" no lugar de "inscrição" nas telas da mestra e do moderador, como traduções de frases inteiras (POMO), só
 * no subsite dos Mestres e só para quem não é admin.
 */
class ProgramVocabulary
{
    /** A frase exata do core => a do programa. */
    const PHRASES = [
        // Ficha do cadastro (registration/single.php)
        'Inscrição' => 'Cadastro',
        'Ficha de inscrição' => 'Ficha do cadastro',
        'Nº de inscrição' => 'Nº do cadastro',
        'Inscrição realizada em' => 'Cadastro realizado em',
        'Inscrição sem data de envio.' => 'Cadastro sem data de envio.',
        'Fase de inscrições' => 'Fase de cadastro',
        // Formulário (registration/edit.php, registration-editable-field.php, registration-info, autosave)
        'Formulário de inscrição' => 'Formulário de cadastro',
        'Informações da inscrição' => 'Informações do cadastro',
        'Os dados da sua inscrição serão salvos automaticamente a cada {{resultTime}} segundos.' => 'Os dados do seu cadastro serão salvos automaticamente a cada {{resultTime}} segundos.',
        // Envio e saída (registration-actions)
        'Ops! Encontramos erros no preenchimento da inscrição' => 'Ops! Encontramos erros no preenchimento do cadastro',
        'Só é possível enviar a inscrição após o preenchimento de todos os campos obrigatórios' => 'Só é possível enviar o cadastro após o preenchimento de todos os campos obrigatórios',
        'Quer enviar sua inscrição?' => 'Quer enviar seu cadastro?',
        'Ao enviar sua inscrição você já estará participando da oportunidade.' => 'Ao enviar, seu cadastro segue para a moderação da Rede.',
        "Sua inscrição foi salva em rascunho. Para retornar, vá ao <b><u>Painel de Controle</u></b>, acesse \n                        <b><u>Minhas Inscrições</u></b> e encontre a inscrição na aba \n                        <b>Não enviadas</b>." => 'Seu cadastro foi salvo em rascunho. Para retornar, vá ao <b><u>Painel de Controle</u></b> e abra o cadastro em <b>Meus cadastros</b>.',
        // Moderação (caminho da lista, tabela de avaliações, tela de avaliação)
        'Período de inscrição' => 'Período de cadastro',
        'Número da inscrição' => 'Número do cadastro',
        'Data da inscrição' => 'Data do cadastro',
    ];

    static function appliesTo(UserInterface $user): bool
    {
        return !$user->is('admin');
    }

    /** @param array<string,string> $phrases por padrão, as do programa; a ficha em análise troca também o selo. */
    static function apply(array $phrases = self::PHRASES): void
    {
        global $i18n;

        $translations = i::get_translations_for_domain('default');

        if ($translations instanceof NOOPTranslations) {
            $translations = $i18n['default'] = new Translations();
        }

        foreach ($phrases as $phrase => $replacement) {
            $translations->add_entry(new EntryTranslations(['singular' => $phrase, 'translations' => [$replacement]]));
        }
    }
}
