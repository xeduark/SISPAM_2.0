<?php

namespace App\Services\Orientacion;

use App\Models\Paciente;
use App\Models\Ticket;

/**
 * Lo que quedó de registrar una visita: a quién se atendió y con qué ticket.
 *
 * El ticket puede venir en null y aun así la visita ser un éxito parcial: si
 * la sede no tiene colas, el paciente y su orden médica **igual se guardan**.
 * Perder la orientación —la consulta a Savia, el contacto confirmado y la
 * fórmula cargada— por un problema de configuración sería mucho peor.
 */
final class ResultadoVisita
{
    /**
     * @param  Paciente  $paciente  Ya creado o actualizado
     * @param  Ticket|null  $ticket  El de esta visita, o null si no se pudo generar
     * @param  bool  $pacienteNuevo  Si el paciente no existía antes en SISPAM
     * @param  int  $ordenesGuardadas  Cuántas fórmulas quedaron colgadas de la visita
     * @param  string|null  $motivoSinTicket  Por qué no hay ticket, en español
     * @param  bool  $turnoNuevo  Falso cuando las hojas se sumaron a una visita que ya estaba abierta
     */
    public function __construct(
        public readonly Paciente $paciente,
        public readonly ?Ticket $ticket,
        public readonly bool $pacienteNuevo,
        public readonly int $ordenesGuardadas,
        public readonly ?string $motivoSinTicket = null,
        public readonly bool $turnoNuevo = true,
    ) {}

    public function tieneTicket(): bool
    {
        return $this->ticket !== null;
    }
}
