<?php

namespace App\Contracts\Domina;

use App\Models\DomicilioEnvio;

/**
 * Puerto hacia Dómina. Sin URLs ni payloads concretos hasta tener la documentación.
 */
interface DominaClientInterface
{
    /**
     * Notifica o registra un envío ante Dómina.
     * Devuelve una referencia externa si el adaptador la genera; null si solo hay seguimiento interno.
     */
    public function enviarEnvio(DomicilioEnvio $envio): ?string;

    /**
     * Consulta el estado de un envío ya referenciado.
     */
    public function consultarEstado(string $referencia): ?DominaEstadoDto;
}
