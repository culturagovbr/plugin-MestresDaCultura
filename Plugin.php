<?php

namespace MDC;

use MapasCulturais\ApiQuery;
use MapasCulturais\App;
use MapasCulturais\Definitions\RegistrationFieldType;
use MapasCulturais\Entities\Registration;
use MapasCulturais\i;

// O autoloader tem mapa plano e registra temas antes de plugins: repetir `MestresDaCultura` faria
// o plugin ser ignorado sem erro, e nenhuma classe ou subpasta pode conter `MDC` no nome.
class Plugin extends \MapasCulturais\Plugin
{
    /** Telas do v1 que só exibem a inscrição; as que salvam não entram aqui. */
    private const V1_READING_ACTIONS = ['registrationview', 'registrationevaluationtionformview'];

    private ?Services\FixedOpportunity $fixedOpportunity = null;

    function register()
    {
        $app = App::i();

        // Ids da oportunidade e dos selos, do ambiente e sem padrão; valem também nos jobs da linha de comando.
        $app->config[Services\FixedOpportunity::CONFIG_KEY] ??= env('MDC_OPPORTUNITY_ID');

        $app->registerController('mdc', Controller::class);
        $app->registerRegistrationFieldType(new RegistrationFieldType(Services\ActingField::definition()));
    }

    function _init()
    {
        $this->registerTreeNodeObjectType();
        $this->renderActingField();
        $this->validateActingFieldOnSend();
        $this->freezeActingFieldOnSend();
        $this->showFieldsOnV1Reading();
        $this->renderPhotoViewer();
        $this->showActingFieldAsTextInPdf();
        $this->blockDisabledEntities();
        $this->blockOpportunityBrowsing();
        $this->limitToOneRegistrationPerUser();
    }

    // `object_type` é enum do Postgres e também lista fixa em PHP; o db-update cobre uma ponta, este hook a outra.
    private function registerTreeNodeObjectType(): void
    {
        App::i()->hook('doctrine.emum(object_type).values', function (&$values) {
            $values['TreeNode'] = Entities\Node::class;
        });
    }

    // O `entity-field` não desenha tipos que não conhece; o componente devolve o valor como evento de input
    // para o salvamento automático do core seguir valendo. Vai em toda página com `entity-field`: o Vue
    // resolve o nome do componente mesmo dentro de `v-if` falso.
    private function renderActingField(): void
    {
        App::i()->hook('template(<<*>>.<<*>>.entity-field-label):after', function () {
            $this->import('mdc-acting-field');

            $slug = Services\ActingField::SLUG;

            echo <<<HTML
                <mdc-acting-field v-if="is('{$slug}')" :entity="entity" :prop="prop" :disabled="readonly || disabled"
                    @change="change({ target: { value: \$event } }, true)"></mdc-acting-field>
            HTML;
        });
    }

    // No envio o core só roda as validações `v::` do tipo; a da árvore entra por aqui. Também recusa o
    // rascunho que tem item tirado da lista depois da última gravação: a pessoa recarrega e vê o que saiu.
    private function validateActingFieldOnSend(): void
    {
        App::i()->hook('entity(Registration).sendValidationErrors', function (&$errors) {
            /** @var Registration $this */
            foreach (Services\ActingField::fieldsOf($this) as $field) {
                $value = $this->{$field->fieldName};

                if ($value === null) {
                    continue;
                }

                if (!Services\ActingField::isValid($value)) {
                    $errors[$field->fieldName][] = Services\ActingField::invalidMessage();
                } elseif ($this->status === Registration::STATUS_DRAFT && $removed = Services\ActingField::removedFromTree($value)) {
                    $errors[$field->fieldName][] = Services\ActingField::staleMessage($removed);
                }
            }
        });
    }

    // Regrava o espelho com os nomes do momento do envio; daí em diante, ele não muda mais.
    private function freezeActingFieldOnSend(): void
    {
        App::i()->hook('entity(Registration).send:before', function () {
            /** @var Registration $this */
            $this->registerFieldsMetadata();

            foreach (Services\ActingField::fieldsOf($this) as $field) {
                $this->{$field->fieldName} = $this->{$field->fieldName};
            }
        });
    }

    // A ficha do v1 não sabe exibir alguns campos do formulário; nas telas de leitura, eles já vão montados
    // em HTML, com o CSS e o JS do plugin.
    private function showFieldsOnV1Reading(): void
    {
        $app = App::i();
        $plugin = $this;

        $isV1Reading = function () use ($app): bool {
            $controller = $app->view->controller ?? null;

            return $controller?->id === 'embedtools' && in_array($controller->action, self::V1_READING_ACTIONS, true);
        };

        $app->hook('view.render(<<*>>):before', function () use ($app, $isV1Reading) {
            if ($isV1Reading()) {
                $app->view->enqueueStyle('app', 'mdc-v1-reading', 'css/plugin-MDC.css', ['main']);
                $app->view->enqueueScript('app', 'mdc-v1-reading', 'js/plugin-MDC.js');
            }
        });

        $app->hook('entity(Registration).jsonSerialize', function (&$result) use ($isV1Reading, $plugin) {
            /** @var Registration $this */
            if (!$isV1Reading()) {
                return;
            }

            // A atuação, que o v1 mostraria como texto cru, vai em cartões, a partir do espelho gravado.
            foreach (Services\ActingField::fieldsOf($this) as $field) {
                if (is_array($result[$field->fieldName] ?? null)) {
                    $result[$field->fieldName] = Services\ActingField::toHtml($result[$field->fieldName]);
                }
            }

            // Paliativo para fotos e vídeos do agente (ver AgentMediaView), só na oportunidade do programa.
            if (!$plugin->fixedOpportunity()->includes($this)) {
                return;
            }

            foreach (Services\AgentMediaView::fieldsOf($this) as $field) {
                if (is_array($result[$field->fieldName] ?? null)) {
                    $result[$field->fieldName] = Services\AgentMediaView::toHtml($field, $result[$field->fieldName]);
                }
            }
        });
    }

    // A foto clicada na ficha do v1 abre aqui, na página de cima: o iframe da ficha cresce com o conteúdo.
    private function renderPhotoViewer(): void
    {
        App::i()->hook('template(<<*>>.<<*>>.main-header):after', function () {
            $this->import('mdc-photo-viewer');

            echo '<mdc-photo-viewer></mdc-photo-viewer>';
        });
    }

    // O PDF da ficha formata os campos pelo core, que não conhece o tipo; na rota dele, o campo é lido
    // como texto em trilhas, que o formatador já sabe quebrar em linhas.
    private function showActingFieldAsTextInPdf(): void
    {
        $app = App::i();

        $app->hook('entity(Opportunity).registrationMetadata', function () use ($app) {
            /** @var \MapasCulturais\Entities\Opportunity $this */
            $controller = $app->view->controller ?? null;

            if ($controller?->id !== 'registration' || $controller->action !== 'exportPDF') {
                return;
            }

            $definitions = $app->getRegisteredMetadata(Registration::class);

            foreach ($this->registrationFieldConfigurations as $field) {
                if ($field->fieldType === Services\ActingField::SLUG && isset($definitions[$field->fieldName])) {
                    $definitions[$field->fieldName]->unserialize = [Services\ActingField::class, 'unserializeAsText'];
                }
            }
        });
    }

    // O limite do core conta por agente responsável, não por pessoa.
    private function limitToOneRegistrationPerUser(): void
    {
        App::i()->hook('entity(Registration).validations', function (&$validations) {
            $validations['owner']['\MDC\Plugin::isTheOnlyRegistrationOfTheUser($this)'] =
                i::__('Você já tem um cadastro nesta oportunidade.');
        });
    }

    static function isTheOnlyRegistrationOfTheUser(Registration $registration): bool
    {
        // Alterar uma inscrição existente não é criar uma segunda.
        if ($registration->id) {
            return true;
        }

        $app = App::i();
        $fixed = (new Services\FixedOpportunity)->get();

        if (!$fixed || $registration->opportunity?->id !== $fixed->id || !$registration->owner?->user) {
            return true;
        }

        // Integridade precisa da verdade do banco, não do que o usuário da requisição enxerga.
        $count = $app->em->createQuery(
            'SELECT COUNT(r.id) FROM MapasCulturais\Entities\Registration r
              JOIN r.owner a
             WHERE r.opportunity = :opportunity AND a.user = :user AND r.status >= 0'
        )
            ->setParameter('opportunity', $fixed)
            ->setParameter('user', $registration->owner->user)
            ->getSingleScalarResult();

        return !$count;
    }

    function fixedOpportunity(): Services\FixedOpportunity
    {
        return $this->fixedOpportunity ??= new Services\FixedOpportunity();
    }

    private function blockOpportunityBrowsing(): void
    {
        $app = \MapasCulturais\App::i();

        $isTheme = fn (): bool => $app->view instanceof \MestresDaCultura\Theme;

        // O plugin roda na instalação inteira; a oportunidade do programa nasce em outro subsite.
        $app->hook('entity(Opportunity).canUser(create)', function ($user, &$result) use ($isTheme) {
            if ($isTheme() && !$user->is('saasSuperAdmin')) {
                $result = false;
            }
        });

        // O tema roda sobre uma oportunidade fixa: não há o que listar.
        $app->hook('GET(<<search|panel>>.opportunities):before', function () use ($app, $isTheme) {
            if ($isTheme() && !$app->user->is('admin')) {
                $app->pass();
            }
        });

        // Esconder o link não fecha a porta: sem isto, qualquer oportunidade da instalação
        // abre por URL direta. Alcança toda ação do controlador, e não só a página.
        $app->hook('ALL(opportunity.<<*>>):before', function () use ($app, $isTheme) {
            if (!$isTheme() || $app->user->is('admin')) {
                return;
            }

            $requested = $this->urlData['id'] ?? null;

            if (!$requested) {
                return;
            }

            $opportunity = $app->repo('Opportunity')->find((int) $requested);
            $fixed = (new Services\FixedOpportunity)->get();

            // A fase é filha da raiz e precisa passar junto: é ela que publica o resultado.
            $root = $opportunity?->parent ?: $opportunity;

            if (!$fixed || !$root || $root->id !== $fixed->id) {
                $app->pass();
            }
        });
    }

    private function blockDisabledEntities(): void
    {
        $app = \MapasCulturais\App::i();

        $isAllowed = fn (string $entity): bool => $app->isEnabled($entity) || $app->user->is('admin');

        $isOwnProfile = function (mixed $id) use ($app): bool {
            $profile = $app->user->profile ?? null;

            return $id && $profile && (int) $id === $profile->id;
        };

        // O próprio perfil fica de fora: toda inscrição pertence a um agente.
        $app->hook('GET(agent.<<*>>):before', function () use ($app, $isAllowed, $isOwnProfile) {
            if ($isAllowed('agents') || $isOwnProfile($this->urlData['id'] ?? null)) {
                return;
            }

            $app->pass();
        });

        // ALL alcança todo método HTTP e nunca a API, que gera o prefixo API(...).
        foreach (['space' => 'spaces', 'project' => 'projects'] as $controller => $entity) {
            $app->hook("ALL({$controller}.<<*>>):before", function () use ($app, $entity, $isAllowed) {
                if (!$isAllowed($entity)) {
                    $app->pass();
                }
            });
        }

        $app->hook('GET(<<search|panel>>.<<agents|spaces|projects>>):before', function () use ($app, $isAllowed) {
            if (!$isAllowed($this->action)) {
                $app->pass();
            }
        });
    }
}
