<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Registration;

/**
 * Perfil público do mestre aprovado, sobre a página de agente do core: o que vem da inscrição aprovada (atuação,
 * cidade) e do selo (desde quando integra a Rede, o certificado).
 */
class MasterProfile
{
    // Quantas atividades o cabeçalho cita; a atuação completa fica na seção própria.
    private const HEADER_ACTIVITIES = 3;

    function __construct(private FixedOpportunity $opportunity, private ProgramSeal $seal)
    {
    }

    /**
     * @return ?array{name: string, knownAs: string, activities: string[], city: string, since: string, certificateUrl: string, actingHtml: string}
     */
    function of(Agent $agent): ?array
    {
        // Só segue a página de quem tem o selo; sem selo, não registra erro.
        if (!$agent->id || !$this->seal->isGivenTo($agent->id)) {
            return null;
        }

        $relation = $this->seal->relationOfAgent($agent);
        $opportunity = $this->opportunity->get();

        if (!$relation || !$opportunity) {
            return null;
        }

        $app = App::i();
        $registration = $app->repo('Registration')->findOneBy(['opportunity' => $opportunity, 'owner' => $agent, 'status' => Registration::STATUS_APPROVED]);

        // Lê da inscrição privada só os dados do perfil; o nome artístico é a cópia moderada (MasterPublicData).
        $app->disableAccessControl();

        try {
            // Registra os campos do formulário como metadados da inscrição.
            $opportunity->registerRegistrationMetadata();
            $acting = $registration ? self::actingOf($registration) : null;
            $city = $registration ? self::cityOf($registration) : '';
            $knownAs = (string) $agent->{MasterPublicData::KNOWN_AS};
            $festivities = FestivityField::festivitiesOf($agent, $registration);
        } finally {
            $app->enableAccessControl();
        }

        return [
            'name' => (string) $agent->name,
            'knownAs' => $knownAs,
            'activities' => $acting ? array_slice(self::activities($acting), 0, self::HEADER_ACTIVITIES) : [],
            'city' => $city,
            'since' => $relation->createTimestamp->format('d/m/Y'),
            'certificateUrl' => $relation->singleUrl,
            // A atuação sem os textos de "Outro".
            'actingHtml' => $acting ? ActingField::toHtml(['nodes' => $acting['nodes'] ?? [], 'others' => []]) : '',
            // O festejo que ela escolheu no cadastro e os que a marcaram, só os publicados.
            'festivities' => $festivities,
        ];
    }

    /**
     * As atividades mais específicas marcadas, as que não têm nada marcado abaixo ("Bilros", "Coco"), na ordem da
     * resposta.
     *
     * @param array{nodes?: array<array{id: int, parent: ?int, name: string}>} $acting
     * @return string[]
     */
    static function activities(array $acting): array
    {
        $nodes = $acting['nodes'] ?? [];
        $parents = array_flip(array_filter(array_column($nodes, 'parent'), fn ($parent) => $parent !== null));

        return array_values(array_map(
            fn ($node) => $node['name'],
            array_filter($nodes, fn ($node) => !isset($parents[$node['id']]))
        ));
    }

    // A resposta da atuação do cadastro; pede o controle de acesso desligado e os metadados registrados.
    static function actingOf(Registration $registration): ?array
    {
        foreach (ActingField::fieldsOf($registration) as $field) {
            // Lido pela definição do campo já vem decodificado; cru, vem em JSON.
            $raw = $registration->{$field->fieldName};
            $value = is_array($raw) ? $raw : ActingField::unserialize($raw);

            if ($value) {
                return $value;
            }
        }

        return null;
    }

    // A cidade do campo "Qual Cidade você vive?", gravada como "Olinda, PE".
    static function cityOf(Registration $registration): string
    {
        foreach ($registration->opportunity->registrationFieldConfigurations as $field) {
            if ($field->fieldType === 'municipio' && is_string($registration->{$field->fieldName})) {
                return $registration->{$field->fieldName};
            }
        }

        return '';
    }
}
