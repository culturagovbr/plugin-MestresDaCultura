<?php

namespace Tests\MDC;

use MailNotification\JobTypes\SendMailNotification;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\PermissionDenied;
use MDC\Services\FixedOpportunity;
use MDC\Services\Moderation;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\UserDirector;

// Ao reenviar a correção, a mestra recebe a confirmação por e-mail e no sino, uma vez por reenvio.
class CorrectionResentTest extends TestCase
{
    use CapturesLog;
    use OpportunityBuilder;
    use UserDirector;

    private function createOpportunity(): Opportunity
    {
        $this->captureLog();
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

    // Enviado e devolvido pela moderação: um campo reaberto, sem prazo.
    private function returnedRegistration(Opportunity $opportunity, User $owner): Registration
    {
        $this->app->disableAccessControl();
        $registration = new Registration;
        $registration->opportunity = $opportunity;
        $registration->owner = $owner->profile;
        $registration->save(true);
        $registration->status = Registration::STATUS_SENT;
        $registration->editableFields = ['field_1'];
        $registration->editableUntil = Moderation::NO_DEADLINE;
        $registration->save(true);
        $this->app->enableAccessControl();

        return $registration;
    }

    private function resend(Registration $registration, User $owner): void
    {
        $this->login($owner);
        $registration->sendEditableFields();
    }

    private function confirmationMails(): int
    {
        return (int) $this->app->conn->fetchOne('SELECT count(*) FROM job WHERE name = :name AND metadata::text LIKE :template', [
            'name' => SendMailNotification::SLUG,
            'template' => '%' . Moderation::MAIL_CORRECTION_RECEIVED . '%',
        ]);
    }

    private function notificationsOf(User $user): array
    {
        return $this->app->conn->fetchFirstColumn('SELECT message FROM notification WHERE user_id = :user', ['user' => $user->id]);
    }

    function testTheMasterIsToldTheCorrectionWasReceived()
    {
        $opportunity = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $opportunity->id;
        $owner = $this->userDirector->createUser();
        $registration = $this->returnedRegistration($opportunity, $owner);

        $this->resend($registration, $owner);

        $this->assertSame(1, $this->confirmationMails(), 'Um e-mail de confirmação para a mestra');
        $notifications = $this->notificationsOf($owner);
        $this->assertCount(1, $notifications, 'E um aviso no sino');
        $this->assertStringContainsString('correção', $notifications[0]);
    }

    function testOnlyOncePerResend()
    {
        $opportunity = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $opportunity->id;
        $owner = $this->userDirector->createUser();
        $registration = $this->returnedRegistration($opportunity, $owner);
        $this->resend($registration, $owner);

        try {
            $registration->sendEditableFields();
            $this->fail('O core não deixa reenviar a mesma correção');
        } catch (PermissionDenied) {
        }

        $this->assertSame(1, $this->confirmationMails(), 'Um e-mail só');
        $this->assertCount(1, $this->notificationsOf($owner), 'Um aviso só');
    }

    function testTheMailSaysItGoesBackToModeration()
    {
        $message = $this->app->renderMailerTemplate(Moderation::MAIL_CORRECTION_RECEIVED, [
            'userName' => 'Maria', 'registrationNumber' => 'on-1', 'registrationLink' => 'http://x/inscricao/1', 'statusNum' => 1,
        ]);
        $text = $message['title'] . strip_tags($message['body']);

        $this->assertStringContainsString('moderação', $text);
        $this->assertStringNotContainsStringIgnoringCase('inscri', $text, 'Cadastro, e não inscrição');
    }

    function testOtherOpportunitiesAreLeftToTheCore()
    {
        $opportunity = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $this->createOpportunity()->id;
        $owner = $this->userDirector->createUser();
        $registration = $this->returnedRegistration($opportunity, $owner);

        $this->resend($registration, $owner);

        $this->assertSame(0, $this->confirmationMails());
        $this->assertSame([], $this->notificationsOf($owner));
    }
}
