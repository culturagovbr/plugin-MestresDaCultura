<?php

namespace Tests\MDC;

use MapasCulturais\i;
use MDC\Services\ProgramVocabulary;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

// Nas telas da mestra e do moderador, "cadastro" no lugar de "inscrição"; o gestor segue com os termos do core. As
// frases trocadas são exatamente as do core.
class ProgramVocabularyTest extends TestCase
{
    use UserDirector;

    /** Aplica o vocabulário só durante `$check` e devolve as traduções de antes. */
    private function withVocabulary(callable $check): void
    {
        global $i18n;
        // Guarda uma cópia das traduções de antes.
        $before = isset($i18n['default']) ? clone $i18n['default'] : null;

        try {
            ProgramVocabulary::apply();
            $check();
        } finally {
            $i18n['default'] = $before;
        }
    }

    function testEveryPhraseIsTheCoreText()
    {
        // Lê as fontes dos módulos de oportunidades e do painel.
        $sources = '';
        foreach (['Opportunities', 'Panel'] as $module) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("/var/www/src/modules/{$module}")) as $file) {
                if (str_ends_with($file->getFilename(), '.php')) {
                    $sources .= file_get_contents($file->getPathname());
                }
            }
        }

        foreach (array_keys(ProgramVocabulary::PHRASES) as $phrase) {
            $this->assertStringContainsString($phrase, $sources, "A frase do core mudou: {$phrase}");
        }
    }

    function testTheMasterReadsCadastro()
    {
        // O texto antes de aplicar o vocabulário no teste.
        $outside = i::__('Formulário de inscrição');

        $this->withVocabulary(function () {
            foreach (ProgramVocabulary::PHRASES as $phrase => $replacement) {
                $this->assertSame($replacement, i::__($phrase));
                $this->assertStringNotContainsStringIgnoringCase('inscri', $replacement, "Sem \"inscrição\": {$replacement}");
            }

            $this->assertStringNotContainsString('participar', i::__('Ao enviar sua inscrição você já estará participando da oportunidade.'), 'A confirmação do envio não fala em participar da oportunidade');
            $this->assertSame('Enviar formulário', i::__('Enviar formulário'), 'O que não está no mapa segue como no core');
        });

        $this->assertSame($outside, i::__('Formulário de inscrição'), 'E volta ao que era no fim do teste');
    }

    function testOnlyWhoIsNotAdmin()
    {
        $this->assertTrue(ProgramVocabulary::appliesTo($this->userDirector->createUser()), 'A mestra e o moderador');
        $this->assertFalse(ProgramVocabulary::appliesTo($this->userDirector->createUser('admin')), 'O gestor segue com os termos do core (D5)');
    }
}
