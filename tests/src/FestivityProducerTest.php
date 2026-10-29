<?php

namespace Tests\MDC;

use MapasCulturais\ApiQuery;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Event;
use MDC\Services\FestivityFields;
use MDC\Services\FestivityForm;
use Tests\Abstract\TestCase;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// As etapas 4 a 6: a instituição só para PJ, grupo ou órgão, os dados da pessoa responsável privados no perfil
// dela e o aceite da publicação carimbado pelo servidor.
class FestivityProducerTest extends TestCase
{
    use BuildsFestivities;
    use RequestFactory;
    use UserDirector;

    function testTheInstitutionAndItsCnpjDependOnWhoRealizes()
    {
        $event = $this->draft();
        $missing = function (string $producer) use ($event) {
            $event->{FestivityFields::PRODUCER} = $producer;
            return FestivityForm::missing($event, 3);
        };

        $this->assertArrayHasKey(FestivityFields::CNPJ, $missing('Pessoa Jurídica sem fins lucrativos'));
        $this->assertArrayHasKey(FestivityFields::INSTITUTION, $missing('Órgão Público'));
        $this->assertArrayNotHasKey(FestivityFields::CNPJ, $missing('Grupo/Coletivo Cultural'), 'O grupo não tem CNPJ');
        $this->assertArrayHasKey(FestivityFields::INSTITUTION, $missing('Grupo/Coletivo Cultural'), 'Mas tem nome');
        $this->assertArrayNotHasKey(FestivityFields::INSTITUTION, $missing('Pessoa Física'));

        $event->{FestivityFields::CNPJ} = '11.111.111/1111-11';
        $this->assertArrayHasKey(FestivityFields::CNPJ, $event->getValidationErrors(), 'CNPJ inválido');
    }

    function testAVisitorSeesNoPersonalDataOfTheResponsible()
    {
        $event = $this->draft();
        $owner = $event->owner;
        $owner->nomeCompleto = 'Celina Pereira dos Santos';
        $owner->dataDeNascimento = '1950-02-14';
        $owner->genero = 'Mulher Cisgênero';
        $owner->emailPrivado = 'celina.privado@example.com';
        $owner->telefone1 = '(75) 99999-0000';
        $owner->emailPublico = 'samba.santabarbara@example.com';
        $owner->save(true);
        $event->{FestivityFields::PRODUCER} = 'Pessoa Jurídica sem fins lucrativos';
        $event->{FestivityFields::CNPJ} = '11.222.333/0001-81';
        $event->{FestivityFields::INSTITUTION} = 'Associação Filhas do Recôncavo';
        // Festejo publicado.
        $event->status = Event::STATUS_ENABLED;
        $event->save(true);
        $this->logout();

        $agent = (new ApiQuery(Agent::class, ['@select' => 'id,nomeCompleto,dataDeNascimento,genero,emailPrivado,telefone1,emailPublico', 'id' => "EQ({$owner->id})"]))->findOne();
        foreach (['nomeCompleto', 'dataDeNascimento', 'genero', 'emailPrivado', 'telefone1'] as $key) {
            $this->assertNull($agent[$key] ?? null, "{$key} não sai para o visitante");
        }
        $this->assertSame('samba.santabarbara@example.com', $agent['emailPublico'] ?? null, 'O e-mail autorizado sai');

        $festivity = (new ApiQuery(Event::class, ['@select' => 'id,' . FestivityFields::CNPJ . ',' . FestivityFields::INSTITUTION, 'id' => "EQ({$event->id})"]))->findOne();
        $this->assertSame($event->id, $festivity['id'] ?? null, 'O festejo publicado sai');
        $this->assertNull($festivity[FestivityFields::CNPJ] ?? null, 'O CNPJ não sai');
        $this->assertSame('Associação Filhas do Recôncavo', $festivity[FestivityFields::INSTITUTION] ?? null);
    }

    function testTheConsentIsStampedByTheServer()
    {
        $event = $this->draft();
        $event->{FestivityFields::CONSENT} = '1';
        $event->save(true);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $event->{FestivityFields::CONSENT});
        $this->assertLessThan(60, abs(time() - strtotime($event->{FestivityFields::CONSENT})), 'A hora é a do servidor');

        $stamped = $event->{FestivityFields::CONSENT};
        $event->save(true);
        $this->assertSame($stamped, $event->{FestivityFields::CONSENT}, 'Salvar de novo não muda a data do aceite');

        // Uma data mandada pelo cliente não vale.
        $event->{FestivityFields::CONSENT} = '2019-05-05 10:00:00';
        $event->save(true);
        $this->assertLessThan(60, abs(time() - strtotime($event->{FestivityFields::CONSENT})), 'Nem uma data forjada');
    }

    // Com o nome preenchido, o responsável conta também para quem edita o festejo sem ver os dados privados do dono.
    function testTheResponsibleCountsForWhoCannotSeeTheirName()
    {
        $event = $this->draft();
        $event->owner->nomeCompleto = 'Celina Pereira dos Santos';
        $event->owner->save(true);

        $this->login($this->userDirector->createUser());
        $this->app->em->clear();
        $event = $this->app->repo('Event')->find($event->id);

        $this->assertNull($event->owner->nomeCompleto, 'Para esta pessoa o nome é privado');
        $this->assertArrayNotHasKey('responsible', FestivityForm::missing($event, 3));
    }

    // O formulário recebe do servidor os dados da pessoa só para quem pode editar o perfil dela.
    function testTheFormBringsThePersonToWhoCanEditIt()
    {
        $event = $this->draft();
        $event->owner->nomeCompleto = 'Celina Pereira dos Santos';
        $event->owner->save(true);
        $person = function () use ($event) {
            $this->app->reset();
            $this->app->run($this->requestFactory->GET('mdc', 'festivity', [$event->id]), false);
            return $this->app->view->jsObject['config']['mdcFestivity']['person'] ?? null;
        };

        $own = $person();
        $this->assertTrue($own['canEdit']);
        $this->assertTrue($own['isOwn']);
        $this->assertSame('Celina Pereira dos Santos', $own['data']['nomeCompleto'] ?? null);

        $this->login($this->userDirector->createUser('admin'));
        $admin = $person();
        $this->assertFalse($admin['isOwn'], 'O suporte não autoriza em nome da pessoa');
        // Quem edita o festejo (agente com controle) e não edita a dona não recebe os dados dela.
        $editor = $this->userDirector->createUser();
        $this->app->disableAccessControl();
        $this->app->repo('Event')->find($event->id)->createAgentRelation($editor->profile, 'group-admin', true, true);
        $this->app->enableAccessControl();
        $this->login($editor);
        $other = $person();
        $this->assertFalse($other['canEdit']);
        $this->assertNull($other['data'], 'Sem o nome, o nascimento, o e-mail e o telefone da dona');
    }

    // Mudar o tipo de realizador apaga o que não vale mais para ele.
    function testChangingWhoRealizesClearsTheInstitution()
    {
        $event = $this->draft();
        $event->{FestivityFields::PRODUCER} = 'Pessoa Jurídica sem fins lucrativos';
        $event->{FestivityFields::INSTITUTION} = 'Associação Filhas do Recôncavo';
        $event->{FestivityFields::CNPJ} = '11.222.333/0001-81';
        $event->{FestivityFields::INSTITUTION_IS_POINT} = 'Sim';
        $event->save(true);

        $event->{FestivityFields::PRODUCER} = 'Grupo/Coletivo Cultural';
        $event->save(true);
        $this->assertEmpty($event->{FestivityFields::CNPJ}, 'O grupo não tem CNPJ');
        $this->assertSame('Associação Filhas do Recôncavo', $event->{FestivityFields::INSTITUTION});

        $event->{FestivityFields::PRODUCER} = 'Pessoa Física';
        $event->save(true);
        $this->assertEmpty($event->{FestivityFields::INSTITUTION});
        $this->assertEmpty($event->{FestivityFields::INSTITUTION_IS_POINT});
    }
}
