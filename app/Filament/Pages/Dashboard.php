<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as EscritorioDeFilament;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;

/**
 * El Escritorio del panel, el mismo de Filament, salvo para:
 *
 * - **las cuentas compartidas**, que entran a consultar y nada más, aunque
 *   la matriz le dé más al rol CONSULTA;
 * - quien lo único que puede hacer es consultar pacientes en Savia.
 *
 * A ellas no les sale en el menú, y entrar a `/admin` las lleva directo a
 * «Consultar paciente». Se redirige en vez de responder 403 porque `/admin`
 * es la puerta del panel: es a donde se llega al iniciar sesión o desde un
 * marcador guardado.
 */
class Dashboard extends EscritorioDeFilament
{
    public static function canAccess(): bool
    {
        $usuario = auth()->user();

        return ! ($usuario?->cuenta_compartida || $usuario?->soloPuedeConsultar());
    }

    public function mount(): void
    {
        // Corre antes que la comprobación de acceso de Filament, que sin esto
        // respondería 403. Una respuesta de verdad y no `$this->redirect()`:
        // esa la aplica Livewire después, cuando el 403 ya habría salido.
        if (! static::canAccess()) {
            throw new HttpResponseException(new RedirectResponse(ConsultarPaciente::getUrl()));
        }
    }
}
