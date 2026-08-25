<?php

namespace MDC;

use MapasCulturais\ApiQuery;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MDC\Services\FixedOpportunity;

class Controller extends \MapasCulturais\Controller
{
    function GET_index()
    {
        $app = App::i();

        $this->requireAuthentication();

        $opportunity = (new FixedOpportunity)->get();

        if (!$opportunity) {
            $app->pass();
        }

        if ($opportunity->status !== Opportunity::STATUS_ENABLED) {
            $app->log->error("MDC: a oportunidade {$opportunity->id} não está publicada; o cadastro não seria acessível a quem o criasse.");

            $app->pass();
        }

        if ($url = $this->existingUrl($opportunity)) {
            $app->redirect($url);
        }

        if (!$opportunity->canUser('register')) {
            $app->log->error("MDC: inscrição recusada na oportunidade {$opportunity->id}; o período está fechado.");

            $app->pass();
        }

        $app->redirect($this->create($opportunity)->editUrl);
    }

    // Sem `@permissions` a consulta filtraria por `view` (ApiQuery.php:3582), que o cache de
    // permissão não guarda para rascunho, e o dono não acharia a própria inscrição.
    private function existingUrl(Opportunity $opportunity): ?string
    {
        $found = (new ApiQuery(Registration::class, [
            '@select' => 'id,editUrl',
            '@permissions' => '@control',
            '@order' => 'id ASC',
            'opportunity' => "EQ({$opportunity->id})",
            'user' => 'EQ(@me)',
            'status' => 'GTE(0)',
        ]))->getFindResult();

        if (!$found) {
            return null;
        }

        // Mais de uma viola a regra de um cadastro por pessoa. Não é para acontecer, e se
        // acontecer não pode passar em branco: a pessoa segue na mais antiga.
        if (count($found) > 1) {
            $ids = implode(', ', array_column($found, 'id'));

            App::i()->log->error("MDC: o usuário " . App::i()->user->id . " tem mais de um cadastro na oportunidade {$opportunity->id}: {$ids}.");
        }

        return reset($found)['editUrl'];
    }

    private function create(Opportunity $opportunity): Registration
    {
        $registration = new Registration;
        $registration->opportunity = $opportunity;
        $registration->owner = App::i()->user->profile;
        $registration->save(true);

        return $registration;
    }
}
