<?php

namespace App\Services\Tickets;

use App\Models\Cola;
use App\Models\Paciente;
use App\Models\Sede;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Turnos\GeneradorDeTurnos;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Crea el ticket de una visita a partir del paciente y su orden médica.
 *
 * Nace en `generado` y **sin medicamentos**: los captura farmacia al alistar.
 */
class GenerarTicket
{
    public function __construct(
        private GeneradorDeTurnos $turnos,
    ) {}

    /**
     * @param  array{alto_costo?: bool, prioridad?: string, motivo_prioridad?: ?string, observaciones?: ?string}  $datos
     */
    public function handle(Paciente $paciente, Sede $sede, User $usuario, array $datos = [], ?CarbonInterface $fecha = null): Ticket
    {
        $altoCosto = (bool) ($datos['alto_costo'] ?? false);
        $dia = $fecha ?? now();

        $cola = $this->colaPara($sede, $altoCosto);

        return DB::transaction(function () use ($paciente, $sede, $cola, $usuario, $datos, $altoCosto, $dia): Ticket {
            $turno = $this->turnos->siguiente($cola, $dia);

            return Ticket::create([
                'numero' => $this->numero($sede, $turno, $dia),
                'turno' => $turno,
                'fecha' => $dia->toDateString(),
                'paciente_id' => $paciente->getKey(),
                'sede_id' => $sede->getKey(),
                'cola_id' => $cola->getKey(),
                'estado' => Ticket::ESTADO_GENERADO,
                'prioridad' => $datos['prioridad'] ?? Ticket::PRIORIDAD_NORMAL,
                'motivo_prioridad' => $datos['motivo_prioridad'] ?? null,
                'alto_costo' => $altoCosto,
                'creado_por' => $usuario->getKey(),
                'observaciones' => $datos['observaciones'] ?? null,
            ]);
        });
    }

    /**
     * La cola que le toca: la de alto costo si está marcado, si no la general.
     *
     * Cuál es cuál lo dice `colas.atiende_alto_costo`, no el prefijo: así se
     * puede reconfigurar por sede sin tocar código.
     */
    public function colaPara(Sede $sede, bool $altoCosto): Cola
    {
        $activas = $sede->colas()->where('activa', true)->orderBy('orden');

        $cola = (clone $activas)->where('atiende_alto_costo', $altoCosto)->first()
            // Si la sede no tiene cola de alto costo, el paciente igual se atiende.
            ?? (clone $activas)->first();

        if ($cola === null) {
            throw new RuntimeException("La sede «{$sede->nombre}» no tiene colas activas. Crea al menos una en Administración → Colas.");
        }

        return $cola;
    }

    /**
     * Número único en todo el sistema: SP-LA30-20261003-A023.
     *
     * Lleva la sede y la fecha, así que no se repite entre sedes ni entre
     * días, y el turno dentro ya es único en su cola. Por eso el módulo de
     * entrega puede seguir buscando solo por número.
     */
    private function numero(Sede $sede, string $turno, CarbonInterface $fecha): string
    {
        $codigo = $sede->codigo ?: 'S'.$sede->getKey();

        return 'SP-'.strtoupper($codigo).'-'.$fecha->format('Ymd').'-'.str_replace('-', '', $turno);
    }
}
