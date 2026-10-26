<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MDC\Services\FixedOpportunity;
use MDC\Services\ProgramMails;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\UserDirector;

// Os e-mails de cadastro iniciado e enviado do programa são os da Rede; as outras oportunidades seguem com os do
// core.
class ProgramMailsTest extends TestCase
{
    use OpportunityBuilder;
    use UserDirector;

    private function registrationIn(Opportunity $opportunity): Registration
    {
        $this->app->disableAccessControl();
        $registration = new Registration;
        $registration->opportunity = $opportunity;
        $registration->owner = $this->userDirector->createUser()->profile;
        $registration->save(true);
        $this->app->enableAccessControl();

        return $registration;
    }

    private function createOpportunity(): Opportunity
    {
        $manager = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($manager);

        return $this->opportunityBuilder
            ->reset(owner: $manager->profile, owner_entity: $manager->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->getInstance();
    }

    /** O template que o módulo do core agendaria, depois dos ganchos. */
    private function templateFor(Registration $registration, string $hook, string $template): string
    {
        $enable = true;
        $params = [];
        $this->app->applyHook($hook, [&$registration, &$template, &$enable, &$params]);

        return $template;
    }

    function testTheProgramSendsTheNetworkMails()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $mine = $this->registrationIn($fixed);
        $elsewhere = $this->registrationIn($other);

        $this->assertSame(ProgramMails::STARTED, $this->templateFor($mine, 'sendMailNotification.registrationStart:beforeEnqueue', 'start_registration'));
        $this->assertSame(ProgramMails::SENT, $this->templateFor($mine, 'sendMailNotification.registrationSend', 'send_registration'));

        $this->assertSame('start_registration', $this->templateFor($elsewhere, 'sendMailNotification.registrationStart:beforeEnqueue', 'start_registration'), 'Outra oportunidade segue com o do core');
        $this->assertSame('send_registration', $this->templateFor($elsewhere, 'sendMailNotification.registrationSend', 'send_registration'));
    }

    function testTheSentMailSaysItGoesToModeration()
    {
        $params = ['userName' => 'Maria', 'registrationNumber' => 'on-1', 'baseUrl' => 'http://x/', 'registrationId' => 1, 'statusNum' => 1];

        foreach ([ProgramMails::STARTED, ProgramMails::SENT] as $template) {
            $message = $this->app->renderMailerTemplate($template, $params);
            // Confere só o texto lido, sem o endereço do link.
            $this->assertStringNotContainsStringIgnoringCase('inscri', $message['title'] . strip_tags($message['body']), "{$template}: cadastro, e não inscrição");
        }

        $this->assertStringContainsString('moderação', $this->app->renderMailerTemplate(ProgramMails::SENT, $params)['body'], 'O enviado diz que segue para a moderação');
    }
}
