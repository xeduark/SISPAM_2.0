<?php

namespace App\Services\Domina;

use App\Contracts\Domina\DominaClientInterface;
use App\Contracts\Domina\DominaEstadoDto;
use App\Models\DomicilioEnvio;

/**
 * No llama a la red. Deja el seguimiento en SISPAM hasta tener la API real.
 */
class DominaClientMock implements DominaClientInterface
{
    public function enviarEnvio(DomicilioEnvio $envio): ?string
    {
        // Referencia simulada solo para pruebas locales; no es un ID de Dómina real.
        return 'MOCK-'.$envio->id;
    }

    public function consultarEstado(string $referencia): ?DominaEstadoDto
    {
        if (! str_starts_with($referencia, 'MOCK-')) {
            return null;
        }

        return new DominaEstadoDto(
            referencia: $referencia,
            estado: 'en_ruta',
            detalle: 'Estado simulado (mock). Sustituir por DominaClientHttp cuando exista la API.',
        );
    }
}
