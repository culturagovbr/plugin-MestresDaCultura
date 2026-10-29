<?php

namespace Tests\MDC;

use MDC\Services\FestivityFields;
use MDC\Services\FestivityForm;
use MDC\Services\ProgramSeal;
use Tests\Abstract\TestCase;
use Tests\Builders\SealBuilder;
use Tests\Traits\UserDirector;

// A etapa 3: o festejo liga só mestras e mestres da Rede, libras e audiodescrição marcam também os campos do core, e
// cada "Sim" pede o seu detalhe.
class FestivityImpactTest extends TestCase
{
    use BuildsFestivities;
    use UserDirector;

    function testOnlyMastersOfTheNetworkAreLinked()
    {
        $admin = $this->userDirector->createUser('admin');
        $this->login($admin);
        $seal = (new SealBuilder)->reset($admin->profile)->fillRequiredProperties()->save()->getInstance();
        $this->app->config[ProgramSeal::CONFIG_KEY] = $seal->id;
        $master = $this->userDirector->createUser()->profile;
        $other = $this->userDirector->createUser()->profile;
        $this->app->disableAccessControl();
        $master->createSealRelation($seal, true, true);
        $this->app->enableAccessControl();

        $event = $this->draft();
        $event->{FestivityFields::MASTERS} = "|{$master->id}|";
        $this->assertArrayNotHasKey(FestivityFields::MASTERS, $event->getValidationErrors());

        $event->{FestivityFields::MASTERS} = "|{$master->id}|{$other->id}|";
        $this->assertArrayHasKey(FestivityFields::MASTERS, $event->getValidationErrors(), 'Quem não tem o selo do programa não entra');

        // A mestra já ligada que sai da Rede segue gravada sem travar o festejo; quem entra agora precisa do selo.
        $event->{FestivityFields::MASTERS} = "|{$master->id}|";
        $event->save(true);
        $this->app->disableAccessControl();
        $master->removeSealRelation($seal);
        $this->app->enableAccessControl();
        $newcomer = $this->userDirector->createUser()->profile;
        $this->app->disableAccessControl();
        $newcomer->createSealRelation($seal, true, true);
        $this->app->enableAccessControl();

        $event->{FestivityFields::MASTERS} = "|{$master->id}|{$newcomer->id}|";
        $this->assertArrayNotHasKey(FestivityFields::MASTERS, $event->getValidationErrors(), 'A que já estava ligada não trava a gravação');

        $event->{FestivityFields::MASTERS} = "|{$master->id}|{$newcomer->id}|{$other->id}|";
        $this->assertArrayHasKey(FestivityFields::MASTERS, $event->getValidationErrors(), 'Quem entra agora precisa do selo');
    }

    function testLibrasAndAudiodescriptionMarkTheCoreFields()
    {
        $event = $this->draft();
        $event->{FestivityFields::ACCESSIBILITY} = ['Rampas', 'Intérprete de libras', 'Audiodescrição'];
        $event->save(true);
        $this->assertSame(['Sim', 'Sim'], [$event->traducaoLibras, $event->descricaoSonora]);

        $event->{FestivityFields::ACCESSIBILITY} = ['Rampas'];
        $event->save(true);
        $this->assertEmpty($event->traducaoLibras, 'Desmarcado, volta a não informado');
        $this->assertEmpty($event->descricaoSonora);
    }

    function testEachYesAsksForItsDetails()
    {
        $event = $this->draft();
        $event->mdcFestejoRouanet = 'Sim';
        $event->{FestivityFields::CULTURE_POINT} = 'Sim';
        $event->{FestivityFields::HAS_ACCESSIBILITY} = 'Sim';

        $missing = FestivityForm::missing($event, 2);
        $this->assertArrayHasKey('mdcFestejoRouanetAnos', $missing, 'Os anos da Rouanet');
        $this->assertArrayHasKey(FestivityFields::CULTURE_POINT_NAME, $missing, 'O nome do Ponto');
        $this->assertArrayHasKey(FestivityFields::ACCESSIBILITY, $missing, 'Os recursos de acessibilidade');
        $this->assertArrayNotHasKey('mdcFestejoPnabAnos', $missing, 'Sem PNAB, sem anos');
        $this->assertArrayNotHasKey(FestivityFields::SHELTER, $missing, 'Sem resposta sobre acolhimento, sem lista');

        $event->{FestivityFields::OTHER_ACCESSIBILITY} = 'Cão-guia';
        $this->assertArrayNotHasKey(FestivityFields::ACCESSIBILITY, FestivityForm::missing($event, 2), 'O "Outro" basta');

        $event->mdcFestejoRouanetAnos = '2023 e dois mil e vinte';
        $this->assertArrayHasKey('mdcFestejoRouanetAnos', $event->getValidationErrors(), 'Anos com quatro dígitos');

        $event->mdcFestejoRouanetAnos = '2023, 2024 e 2025';
        $this->assertArrayNotHasKey('mdcFestejoRouanetAnos', $event->getValidationErrors());
        $this->assertArrayNotHasKey('mdcFestejoRouanetAnos', FestivityForm::missing($event, 2));

        // Ano futuro é recusado.
        $event->mdcFestejoRouanetAnos = '2024, ' . ((int) date('Y') + 1);
        $this->assertArrayHasKey('mdcFestejoRouanetAnos', $event->getValidationErrors(), 'Ano futuro');
    }
}
