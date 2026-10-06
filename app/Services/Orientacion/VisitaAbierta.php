<?php

namespace App\Services\Orientacion;

use App\Models\Paciente;
use App\Models\Sede;
use App\Models\Ticket;

/**
 * Una visita del paciente que todavía está viva, si la hay.
 *
 * Un paciente puede volver al mostrador el mismo día porque perdió el papel,
 * porque se cansó de esperar y volvió a la fila, o porque trae una fórmula
 * nueva de verdad. Solo la última merece otro turno, así que conviene decirlo
 * antes de generarlo.
 *
 * ## Por qué solo mira la sede de quien atiende
 *
 * Las tres salidas que se ofrecen —reimprimir, sumar las fotos a esa visita o
 * generar otra— solo tienen sentido sobre un ticket de la propia sede:
 * imprimir ya exige misma sede, y entrega también. Avisar de un ticket de otra
 * sede sería ruido sobre el que nadie puede hacer nada.
 */
final class VisitaAbierta
{
    /** Tiene un turno de hoy que nadie ha cerrado. */
    public const MOTIVO_EN_CURSO = 'en_curso';

    /**
     * Le quedaron faltantes de una atención anterior.
     *
     * Los parciales **no vencen**: ese paciente sí fue atendido y vuelve por
     * lo que le faltó, y entrega lo encuentra por su número sin importar la
     * fecha. Así que no necesita turno nuevo para reclamarlos.
     */
    public const MOTIVO_PENDIENTES = 'pendientes';

    private function __construct(
        public readonly Ticket $ticket,
        public readonly string $motivo,
    ) {}

    /**
     * La visita viva del paciente en esa sede, o null si no hay ninguna.
     *
     * El turno de hoy manda sobre el parcial viejo: es la visita en curso, y
     * es la que explica por qué el paciente está otra vez en el mostrador.
     */
    public static function buscar(Paciente $paciente, ?Sede $sede): ?self
    {
        if ($sede === null) {
            return null;
        }

        $deLaSede = Ticket::query()
            ->where('paciente_id', $paciente->getKey())
            ->where('sede_id', $sede->getKey())
            ->latest('id');

        $enCurso = (clone $deLaSede)
            ->whereDate('fecha', now()->toDateString())
            ->whereIn('estado', [
                Ticket::ESTADO_GENERADO,
                Ticket::ESTADO_EN_ALISTAMIENTO,
                Ticket::ESTADO_LISTO,
            ])
            ->first();

        if ($enCurso !== null) {
            return new self($enCurso, self::MOTIVO_EN_CURSO);
        }

        $conPendientes = (clone $deLaSede)
            ->where('estado', Ticket::ESTADO_PARCIAL)
            ->first();

        return $conPendientes === null
            ? null
            : new self($conPendientes, self::MOTIVO_PENDIENTES);
    }

    public function esDeHoy(): bool
    {
        return $this->motivo === self::MOTIVO_EN_CURSO;
    }

    public function titulo(): string
    {
        return $this->esDeHoy()
            ? 'Este paciente ya tiene una visita hoy'
            : 'Este paciente tiene medicamentos pendientes';
    }

    /**
     * Qué conviene hacer, dicho para quien está en el mostrador.
     */
    public function detalle(): string
    {
        if ($this->esDeHoy()) {
            return 'Si volvió porque perdió el papel o porque trae otra hoja de la misma fórmula, '
                .'no necesita otro turno. Genera uno nuevo solo si trae una fórmula distinta.';
        }

        $fecha = $this->ticket->fecha?->format('d/m/Y') ?? 'una visita anterior';

        return "Le quedaron faltantes de la visita del {$fecha}. Si viene por esos, no necesita "
            .'turno nuevo: entrega lo atiende por el número de ese ticket. Genera uno nuevo solo '
            .'si trae una fórmula distinta.';
    }

    /**
     * El estado del ticket, en español, para mostrarlo junto al turno.
     */
    public function estado(): string
    {
        return Ticket::ESTADOS[$this->ticket->estado] ?? $this->ticket->estado;
    }
}
