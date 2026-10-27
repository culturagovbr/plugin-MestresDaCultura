<?php

namespace Tests\MDC;

use MapasCulturais\ApiQuery;
use MapasCulturais\Entities\Event;
use MDC\Services\FestivityFields;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

// O festejo é um Evento do core com os campos do programa, definidos num lugar só, e sem a obrigatoriedade da
// descrição curta, da classificação etária e da linguagem.
class FestivityFieldsTest extends TestCase
{
    use UserDirector;

    function testTheFestejoFieldsAreEventMetadata()
    {
        $metadata = Event::getPropertiesMetadata();

        foreach (array_keys(FestivityFields::definitions()) as $key) {
            $this->assertArrayHasKey($key, $metadata, "{$key} registrado no evento");
        }
        $this->assertTrue($metadata[FestivityFields::CONSENT]['private'], 'O aceite não é público');
        $this->assertFalse($metadata[FestivityFields::SIZE]['private'], 'O porte é público');
        $this->assertSame(['Micro', 'Pequeno', 'Médio', 'Grande', 'Mega'], array_keys($metadata[FestivityFields::SIZE]['options']));
    }

    function testTheListsComeFromTheSpreadsheet()
    {
        $classification = FestivityFields::classification();

        $this->assertSame(['Celebrações e Rituais', 'Expressões, Manifestações e Brincadeiras', 'Identidades, Territórios e Comunidades'], array_column($classification, 'segmento'));
        $this->assertSame(24, array_sum(array_map(fn ($segment) => count($segment['tipologias']), $classification)));
        $this->assertContains('Festas Étnico-Culturais', $classification[2]['tipologias'], 'A grafia corrigida');
        $this->assertCount(16, FestivityFields::economy());
        $this->assertSame(['Mobilidade', 'Comunicacional e Informações', 'Conforto Sensorial'], array_column(FestivityFields::accessibility(), 'tipo'));
        $this->assertCount(8, FestivityFields::shelter());
    }

    function testTheCoreRequirementsTheSpreadsheetDoesNotAskAreLifted()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $event = new Event;
        $event->name = 'Samba de Roda de Santa Bárbara';
        $event->owner = $user->profile;

        $errors = $event->getValidationErrors();

        foreach (['shortDescription', 'classificacaoEtaria', 'term-linguagem'] as $key) {
            $this->assertArrayNotHasKey($key, $errors, "{$key} não é obrigatório no subsite");
        }
    }

    // O resto da validação segue, e as telas mostram os campos sem "obrigatório".
    function testWhatTheFestejoUsesStillCountsAndTheScreensFollow()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $event = new Event;
        $event->owner = $user->profile;
        $event->shortDescription = str_repeat('a', 401);

        $errors = $event->getValidationErrors();
        $metadata = Event::getPropertiesMetadata();

        $this->assertArrayHasKey('name', $errors, 'Sem nome, segue o erro');
        $this->assertArrayHasKey('shortDescription', $errors, 'A descrição curta segue com o limite de tamanho');
        $this->assertFalse($metadata['shortDescription']['required'], 'Descrição curta sem "obrigatório"');
        $this->assertFalse($metadata['classificacaoEtaria']['required'], 'Classificação etária sem "obrigatório"');
        $this->assertFalse((bool) $this->app->getRegisteredTaxonomies(Event::class)['linguagem']->required, 'Linguagem sem "obrigatório"');
    }

    function testAVisitorSeesOnlyThePublicFields()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $event = new Event;
        $event->name = 'Samba de Roda de Santa Bárbara';
        $event->owner = $user->profile;
        $event->{FestivityFields::SIZE} = 'Médio';
        $event->{FestivityFields::CONSENT} = '2026-10-27 12:00:00';
        $event->save(true);
        $this->logout();

        $found = (new ApiQuery(Event::class, ['@select' => 'id,' . FestivityFields::SIZE . ',' . FestivityFields::CONSENT, 'id' => "EQ({$event->id})"]))->find();

        $this->assertSame('Médio', $found[0][FestivityFields::SIZE] ?? null);
        // A API devolve a chave pedida com null quando o dado é privado.
        $this->assertNull($found[0][FestivityFields::CONSENT] ?? null, 'O aceite não sai para o visitante');
    }
}
