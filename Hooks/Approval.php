<?php

namespace MDC\Hooks;

use MDC\Plugin;
use MDC\Services;
use MapasCulturais\App;
use MapasCulturais\Entities\Registration;
use MapasCulturais\i;

/**
 * Aprovação do cadastro dos Mestres: selo do programa, dados públicos, aviso com o link do certificado e cópia
 * para a fase final.
 */
final class Approval
{
    static function register(Plugin $plugin): void
    {
        self::applyProgramSeal($plugin);
        self::notifyApproval($plugin);
        self::allowFinalPhaseSync($plugin);
    }

    // Selo do programa e dados públicos da busca: entram na aprovação da fase de cadastro e saem quando o cadastro
    // deixa de estar aprovado.
    private static function applyProgramSeal(Plugin $plugin): void
    {
        $app = App::i();

        $isRegistrationPhase = fn (Registration $registration): bool => $registration->opportunity->id === $plugin->fixedOpportunity()->get()?->id;

        // Na aprovação da fase de cadastro, nesta ordem: o selo, os dados públicos e o aviso com o link do certificado.
        $app->hook('entity(Registration).status(approved)', function () use ($plugin, $isRegistrationPhase) {
            /** @var Registration $this */
            if ($isRegistrationPhase($this)) {
                $plugin->programSeal()->apply($this);
                (new Services\MasterPublicData)->publish($this);
                (new Services\Moderation)->notifyApproved($this, $plugin->programSeal()->relationOf($this)?->singleUrl);
            }
        });

        $app->hook('entity(Registration).status(<<draft|sent|invalid|notapproved|waitlist>>)', function () use ($plugin, $isRegistrationPhase) {
            /** @var Registration $this */
            if ($isRegistrationPhase($this)) {
                $plugin->programSeal()->remove($this);
                (new Services\MasterPublicData)->clear($this->owner);
            }
        });

        // O cadastro aprovado excluído leva o selo e os dados públicos.
        $app->hook('entity(Registration).remove:before', function () use ($plugin, $isRegistrationPhase) {
            /** @var Registration $this */
            if ($this->status === Registration::STATUS_APPROVED && $isRegistrationPhase($this)) {
                $plugin->programSeal()->remove($this);
                (new Services\MasterPublicData)->clear($this->owner);
            }
        });

        // Certificado de vínculo de selo inexistente responde "não encontrado".
        $app->hook('GET(seal.sealRelation):before', function () use ($app) {
            if (Plugin::isTheme() && !$app->repo('SealRelation')->find((int) ($this->data['id'] ?? 0))) {
                $app->pass();
            }
        });

        // Barra de ações do certificado logo após o cabeçalho, só no selo do programa e no dos festejos.
        $app->hook('template(seal.sealRelation.main-header):after', function () use ($app, $plugin) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $relation = $app->repo('SealRelation')->find((int) ($this->controller->data['id'] ?? 0));

            if ($relation && ($plugin->programSeal()->isTheProgramSeal($relation->seal) || $plugin->festivitySeal()->isTheFestivitySeal($relation->seal))) {
                $this->import('mdc-certificate-actions');
                echo '<mdc-certificate-actions></mdc-certificate-actions>';
            }
        });
    }

    // Modelo do e-mail de aprovação, enviado em applyProgramSeal depois do selo.
    private static function notifyApproval(Plugin $plugin): void
    {
        App::i()->config['mailer.templates'][Services\Moderation::MAIL_APPROVED] = [
            'title' => i::__('[Rede de Mestras e Mestres] Seu cadastro foi aprovado'),
            'template' => Services\Moderation::MAIL_APPROVED . '.html',
        ];
    }

    // Na oportunidade fixa, o job de cópia para a fase final roda sem controle de acesso, religado ao fim de qualquer
    // execução do job.
    private static function allowFinalPhaseSync(Plugin $plugin): void
    {
        $app = App::i();
        $disabled = false;

        $app->hook('job(' . \OpportunityPhases\Jobs\SyncPhaseRegistrations::SLUG . ').execute:before', function () use ($app, $plugin, &$disabled) {
            /** @var \MapasCulturais\Entities\Job $this */
            if ($plugin->fixedOpportunity()->matches($this->opportunity)) {
                $app->disableAccessControl();
                $disabled = true;
            }
        });

        $app->hook('app.executeJob:after', function () use ($app, &$disabled) {
            if ($disabled) {
                $app->enableAccessControl();
                $disabled = false;
            }
        });
    }
}
