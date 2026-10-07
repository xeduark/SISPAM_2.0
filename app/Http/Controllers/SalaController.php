<?php

namespace App\Http\Controllers;

use App\Models\Llamado;
use App\Models\Sede;
use App\Services\Turnos\LlamadorDeTurnos;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * La pantalla del televisor de la sala de espera.
 *
 * **Es pública a propósito:** un televisor no inicia sesión. Por eso solo
 * muestra **turno y ventanilla**: ni el nombre del paciente, ni su documento,
 * ni nada de la fórmula. Lo que se ve en la pantalla es lo mismo que se grita
 * en voz alta en la sala.
 */
class SalaController extends Controller
{
    public function __construct(
        private LlamadorDeTurnos $llamador,
    ) {}

    /** La pantalla, ya con el primer dato pintado. */
    public function mostrar(Sede $sede): View
    {
        abort_unless((bool) $sede->activa, 404);

        return view('sala.turnos', [
            'sede' => $sede,
            'turnos' => $this->turnosDeLaSede($sede),
        ]);
    }

    /** Lo que pide la pantalla cada pocos segundos. */
    public function turnos(Sede $sede): JsonResponse
    {
        abort_unless((bool) $sede->activa, 404);

        return response()->json([
            'sede' => $sede->nombre,
            'turnos' => $this->turnosDeLaSede($sede),
        ]);
    }

    /**
     * Los últimos llamados, el más reciente primero.
     *
     * Aquí se decide qué sale a una pantalla sin sesión: turno, ventanilla,
     * hora y si es preferencial. Nada más. Agregar un campo a esta lista es
     * publicarlo en la sala de espera.
     *
     * @return list<array{turno: string, ventanilla: ?string, hora: string, preferencial: bool}>
     */
    private function turnosDeLaSede(Sede $sede): array
    {
        return $this->llamador->ultimosLlamados((int) $sede->getKey(), 5)
            ->map(fn (Llamado $llamado): array => [
                'turno' => (string) $llamado->ticket?->turno,
                'ventanilla' => $llamado->ventanilla?->nombre,
                'hora' => (string) $llamado->created_at?->format('h:i a'),
                'preferencial' => (bool) $llamado->ticket?->esPreferencial(),
            ])
            ->values()
            ->all();
    }
}
