<?php

namespace MDC\Hooks;

use MDC\Plugin;
use MDC\Services;
use MapasCulturais\ApiQuery;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\i;

/**
 * Gestão do cadastro: lista de inscrições, planilha e data de envio da avaliação, para a comissão e o gestor do
 * subsite dos Mestres.
 */
final class Management
{
    static function register(Plugin $plugin): void
    {
        self::adjustRegistrationsSpreadsheet($plugin);
        self::adjustTheRegistrationsList($plugin);
        self::showEvaluationSentDate($plugin);
    }

    // A planilha de inscrições ganha a idade e a atuação e formata fotos, vídeos e seções (Services\RegistrationsSpreadsheet).
    private static function adjustRegistrationsSpreadsheet(Plugin $plugin): void
    {
        $app = App::i();
        $spreadsheet = new Services\RegistrationsSpreadsheet($plugin->fixedOpportunity());
        $job = 'SpreadsheetJob(' . Services\RegistrationsSpreadsheet::JOB . ')';

        $app->hook("{$job}.getHeader:after", function ($job, &$header) use ($spreadsheet) {
            $spreadsheet->removeEmptyColumns($job->owner, $header);
            $spreadsheet->addAgeColumn($job->owner, $header);
        });

        $app->hook("{$job}.getBatch:before", function ($job, &$query) use ($spreadsheet) {
            $spreadsheet->startBatch($job->owner, $query);
        });

        $app->hook('entity(Opportunity).registrationMetadata', function () use ($spreadsheet) {
            /** @var Opportunity $this */
            $spreadsheet->readAsCells($this);
        });

        $app->hook("{$job}.getBatch:after", function ($job, &$rows) use ($spreadsheet) {
            $spreadsheet->fillAge($rows);
            $spreadsheet->finishBatch();
        });
    }

    // Coluna da moderação e colunas do programa só na lista de inscrições do programa (Services\RegistrationsTable).
    private static function adjustTheRegistrationsList(Plugin $plugin): void
    {
        $app = App::i();
        $isProgram = fn (?Opportunity $opportunity): bool => Plugin::isTheme() && $plugin->fixedOpportunity()->isConfigured() && $plugin->fixedOpportunity()->matches($opportunity);

        $app->hook('entity(Registration).propertiesMetadata', function (&$result) {
            if (Plugin::isTheme()) {
                $result[Services\RegistrationsTable::MODERATION] = Services\RegistrationsTable::property();
            }
        });

        $app->hook('component(opportunity-registrations-table).additionalHeaders', function (&$headers, &$select) use ($app, $isProgram) {
            if ($isProgram($app->view->controller->requestedEntity ?? null)) {
                Services\RegistrationsTable::adjust($headers, $select);
                $app->view->jsObject['config']['mdcRegistrationsTable'] = ['after' => Services\RegistrationsTable::PLACED_AFTER, 'hidden' => Services\RegistrationsTable::UNUSED];
            }
        });

        // Cada linha da lista ganha a situação da moderação.
        $app->hook('ApiQuery(Registration).findResult', function (&$result) use ($app, $isProgram) {
            $request = $app->request;

            if (!$request || $request->controllerId !== 'opportunity' || $request->action !== 'findRegistrations') {
                return;
            }

            if ($isProgram($app->repo('Opportunity')->find((int) $request->get('@opportunity')))) {
                foreach ($result as &$row) {
                    if (is_array($row) && array_key_exists('status', $row)) {
                        $row[Services\RegistrationsTable::MODERATION] = Services\RegistrationsTable::label($row);
                    }
                }
                unset($row);
            }
        });

        // O padrão de colunas do programa (registrations-table.js), junto do script do componente.
        $app->hook('Theme::enqueueComponentScript', function ($result, string $component) {
            if ($component === 'opportunity-registrations-table' && Plugin::isTheme()) {
                $this->enqueueScript('components', 'mdc-registrations-table', 'js/registrations-table.js', ['opportunity-registrations-table']);
            }
        }, 1000);
    }

    // Data de envio da avaliação na lista de avaliações do programa (Services\EvaluationsTable).
    private static function showEvaluationSentDate(Plugin $plugin): void
    {
        $app = App::i();
        $isProgramList = function () use ($app, $plugin): bool {
            $request = $app->request;

            return Plugin::isTheme() && $request && $request->controllerId === 'opportunity' && $request->action === 'findEvaluations'
                && $plugin->fixedOpportunity()->isConfigured()
                && $plugin->fixedOpportunity()->matches($app->repo('Opportunity')->find((int) $request->get('@opportunity')));
        };

        $app->hook('ApiQuery(RegistrationEvaluation).params', function (&$params) use ($isProgramList) {
            if (!empty($params['@select']) && !str_contains($params['@select'], 'sentTimestamp') && $isProgramList()) {
                $params['@select'] .= ',sentTimestamp';
            }
        });

        $app->hook('ApiQuery(RegistrationEvaluation).findResult', function (&$result) use ($isProgramList) {
            if ($isProgramList()) {
                Services\EvaluationsTable::format($result);
            }
        });

        $app->hook('component(opportunity-evaluations-table).additionalHeaders', function (&$headers) use ($app, $plugin) {
            $phase = $app->view->controller->requestedEntity ?? null;

            if (Plugin::isTheme() && $plugin->fixedOpportunity()->isConfigured() && $plugin->fixedOpportunity()->matches($phase)) {
                Services\EvaluationsTable::adjust($headers, $app->view->controller->action === 'userEvaluations');
                $app->view->jsObject['config']['mdcEvaluationsTable'] = ['opportunity' => $phase->id, 'visible' => [Services\EvaluationsTable::SLUG], 'tables' => Services\EvaluationsTable::TABLES, 'hidden' => Services\EvaluationsTable::UNUSED];
            }
        });

        $app->hook('Theme::enqueueComponentScript', function ($result, string $component) {
            if ($component === 'opportunity-evaluations-table' && Plugin::isTheme()) {
                $this->enqueueScript('components', 'mdc-evaluations-table', 'js/evaluations-table.js', ['opportunity-evaluations-table']);
            }
        }, 1000);
    }
}
