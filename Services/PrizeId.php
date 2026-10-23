<?php

namespace MDC\Services;

use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationFieldConfiguration;
use MapasCulturais\i;

/**
 * ID do painel de dados da Rede: quem foi premiado em edital do MinC informa o ID ou marca que não o localizou, regra
 * conferida no envio. Os dois campos são achados pela marca `mdcRole` na configuração.
 */
class PrizeId
{
    const ROLE_ID = 'prize-id';

    const ROLE_NOT_FOUND = 'prize-id-missing';

    /**
     * Os dois campos, quando aparecem para esta inscrição (só para quem foi premiado).
     *
     * @return array{id: RegistrationFieldConfiguration, notFound: RegistrationFieldConfiguration}|null
     */
    static function fieldsOf(Registration $registration): ?array
    {
        $fields = [];

        foreach ($registration->opportunity->registrationFieldConfigurations as $field) {
            $role = $field->config['mdcRole'] ?? null;

            if (in_array($role, [self::ROLE_ID, self::ROLE_NOT_FOUND], true) && $registration->isFieldVisisble($field)) {
                $fields[$role] = $field;
            }
        }

        return isset($fields[self::ROLE_ID], $fields[self::ROLE_NOT_FOUND])
            ? ['id' => $fields[self::ROLE_ID], 'notFound' => $fields[self::ROLE_NOT_FOUND]]
            : null;
    }

    // A caixa desmarcada é gravada como '' (e nunca respondida, como null).
    static function isMissing(mixed $id, mixed $notFound): bool
    {
        return trim((string) $id) === '' && !filter_var($notFound, FILTER_VALIDATE_BOOLEAN);
    }

    static function message(): string
    {
        return i::__('Informe o seu ID ou marque que não o localizou.');
    }
}
