<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\Registration;
use MapasCulturais\i;

/**
 * O campo "Identifique o festejo no calendário" do cadastro dos Mestres: guarda o id de um festejo publicado no
 * Calendário; a resposta em texto segue valendo como texto. Daqui sai o vínculo da mestra com o festejo, no perfil
 * dela e na página do festejo, sem alterar o festejo.
 */
class FestivityField
{
    const SLUG = 'mdc-festejo';

    // A pergunta do formulário dos Mestres que passa a este tipo (convertTheMastersQuestion, no db-update).
    const TITLE = 'Identifique o festejo no calendário';

    static function definition(): array
    {
        return [
            'slug' => self::SLUG,
            'name' => i::__('Festejo do Calendário'),
            'viewTemplate' => 'registration-field-types/' . self::SLUG,
            'configTemplate' => 'registration-field-types/' . self::SLUG . '-config',
            'serialize' => fn ($value) => ($value = trim((string) $value)) === '' ? null : $value,
            'validations' => [
                '\MDC\Services\FestivityField::isValid($value)' => i::__('Escolha um festejo publicado no Calendário'),
            ],
        ];
    }

    // Um id é de um festejo publicado; o texto é a resposta de antes da busca.
    static function isValid(mixed $value): bool
    {
        $value = trim((string) $value);

        if ($value === '' || !ctype_digit($value)) {
            return true;
        }

        return self::isFestivity((int) $value);
    }

    // Um festejo publicado do Calendário: tem o selo dos festejos (FestivitySeal).
    private static function isFestivity(int $id): bool
    {
        $event = App::i()->repo('Event')->find($id);

        return $event && $event->status === Event::STATUS_ENABLED && (new FestivitySeal)->relationOf($event);
    }

    // Para a ficha em PDF e a planilha: o nome do festejo, e não o id.
    static function toText(mixed $value): mixed
    {
        if (!is_string($value) || !ctype_digit($value)) {
            return $value;
        }

        return App::i()->repo('Event')->find((int) $value)?->name ?? $value;
    }

    // Passa a pergunta de texto do formulário dos Mestres a este tipo, com a ajuda da busca; as respostas em texto
    // seguem como estão.
    static function convertTheMastersQuestion(): void
    {
        $opportunity = (new FixedOpportunity)->get();

        if ($opportunity) {
            App::i()->conn->executeStatement(
                "UPDATE registration_field_configuration SET field_type = :slug, description = :description
                 WHERE opportunity_id = :opportunity AND field_type IN ('text', :slug) AND title = :title",
                ['slug' => self::SLUG, 'description' => self::description(), 'opportunity' => $opportunity->id, 'title' => self::TITLE]
            );
        }
    }

    static function description(): string
    {
        return i::__('Digite o nome do festejo e escolha na lista dos que estão publicados no Calendário.');
    }

    /** @return list<int> as mestras aprovadas (com o selo) que escolheram o festejo no cadastro */
    static function mastersWhoChose(Event $event, ProgramSeal $seal): array
    {
        // Sem o programa configurado, nenhuma mestra, sem registrar erro.
        $fixed = new FixedOpportunity;
        $opportunity = $fixed->isConfigured() ? $fixed->get() : null;
        $keys = $opportunity ? self::keysOf($opportunity->id) : [];

        if (!$keys) {
            return [];
        }

        $agents = App::i()->conn->fetchFirstColumn(
            'SELECT DISTINCT r.agent_id FROM registration r JOIN registration_meta m ON m.object_id = r.id
             WHERE r.opportunity_id = :opportunity AND r.status = :approved AND m.key IN (:keys) AND m.value = :event',
            ['opportunity' => $opportunity->id, 'approved' => Registration::STATUS_APPROVED, 'keys' => $keys, 'event' => (string) $event->id],
            ['keys' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );

        return $seal->givenAmong($agents);
    }

    /**
     * Os festejos do Calendário da mestra (publicados e com o selo dos festejos): o que ela escolheu no cadastro
     * aprovado e os que a marcaram.
     *
     * @return list<array{id: int, name: string, url: string, place: string}>
     */
    static function festivitiesOf(Agent $agent, ?Registration $registration): array
    {
        $app = App::i();
        $seal = (new FestivitySeal)->configuredId();
        $ids = $seal ? $app->conn->fetchFirstColumn(
            "SELECT e.id FROM event e
               JOIN event_meta m ON m.object_id = e.id AND m.key = :key
               JOIN seal_relation r ON r.object_id = e.id AND r.object_type = :type AND r.seal_id = :seal
             WHERE e.status = :enabled AND m.value LIKE :agent",
            ['key' => FestivityFields::MASTERS, 'type' => Event::class, 'seal' => $seal, 'enabled' => Event::STATUS_ENABLED, 'agent' => "%|{$agent->id}|%"]
        ) : [];

        foreach ($registration ? self::keysOf($registration->opportunity->id) : [] as $key) {
            $value = (string) $registration->getMetadata($key);
            if (ctype_digit($value) && self::isFestivity((int) $value)) {
                array_unshift($ids, $value);
            }
        }

        $festivities = [];

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $event = $app->repo('Event')->find($id);

            if ($event && $event->status === Event::STATUS_ENABLED) {
                $festivities[] = [
                    'id' => $event->id,
                    'name' => (string) $event->name,
                    'url' => $event->singleUrl,
                    'place' => implode(', ', array_filter([$event->{FestivityFields::CITY}, $event->{FestivityFields::UF}])),
                ];
            }
        }

        return $festivities;
    }

    /** @return list<string> as chaves dos campos deste tipo na oportunidade ("field_400") */
    private static function keysOf(int $opportunityId): array
    {
        return array_map(
            fn ($id) => "field_{$id}",
            App::i()->conn->fetchFirstColumn('SELECT id FROM registration_field_configuration WHERE opportunity_id = :opportunity AND field_type = :slug', ['opportunity' => $opportunityId, 'slug' => self::SLUG])
        );
    }
}
