<?php

namespace MDC\Services;

/**
 * Os e-mails de cadastro iniciado e enviado do programa são os da Rede (templates/pt_BR); as outras oportunidades
 * seguem com os do core.
 */
class ProgramMails
{
    const STARTED = 'mdc_cadastro_iniciado';

    const SENT = 'mdc_cadastro_enviado';

    /** O template do core => o da Rede. A fase de coleta de dados (start_data_collection_phase) segue a do core. */
    const REPLACES = ['start_registration' => self::STARTED, 'send_registration' => self::SENT];
}
