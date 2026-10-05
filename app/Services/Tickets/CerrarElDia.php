<?php

namespace App\Services\Tickets;

use App\Models\Auditoria;
use App\Models\Sede;
use App\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * El cierre del día: lo que nadie alcanzó a atender queda «vencido».
 *
 * Sin esto los tickets de ayer quedan abiertos para siempre. No se ven en la
 * sala —esa pantalla solo muestra lo de hoy— pero sí en el listado de Tickets
 * y en cualquier conteo de «cuántos están esperando», que iría creciendo día
 * tras día sin que nadie pueda hacer nada al respecto.
 *
 * ## Qué vence y qué no
 *
 * Vencen los tres estados en los que **el paciente nunca fue atendido**:
 * `generado` (farmacia no alcanzó a tomarlo), `en_alistamiento` (lo tomó y no
 * terminó) y `listo` (quedó listo y el paciente no volvió).
 *
 * **`parcial` no vence.** Ese paciente sí fue atendido: se llevó parte de su
 * fórmula y le quedaron faltantes, que son justamente los que vuelve a
 * reclamar otro día. `TicketConsultaDb::buscarPorNumero()` encuentra el ticket
 * por su número sin importar la fecha, así que el flujo de pendientes depende
 * de que siga vivo. Vencerlo le quitaría al paciente un medicamento que ya
 * tiene ganado.
 *
 * `entregado`, `anulado` y `vencido` ya están cerrados y no se tocan.
 *
 * ## Por qué cierra «hasta ayer» y no «el día de hoy a las 11 p.m.»
 *
 * Cerrar hacia atrás es idempotente: se puede correr a cualquier hora, dos
 * veces seguidas o tres días después de un servidor apagado, y el resultado es
 * el mismo. Una tarea atada a las 11 p.m. que no corrió deja el día abierto
 * para siempre. Y como nunca toca la fecha en curso, no hay forma de que
 * venza un ticket que alguien está a punto de atender.
 *
 * ## Por qué una sola línea de auditoría por sede
 *
 * El cambio se hace con un `update` masivo, que **no dispara los eventos del
 * modelo**: en vez de doscientas líneas iguales en `auditorias`, queda una por
 * sede con el total y el desglose. Cada ticket ya cuenta su propia historia
 * con `estado = vencido` y `cerrado_en`; repetirlo doscientas veces llenaría
 * la auditoría de ruido sin agregar un dato. Es el mismo criterio que con los
 * llamados en `docs/llamado-de-turnos.md`.
 */
class CerrarElDia
{
    /**
     * Los estados en los que el paciente nunca fue atendido.
     *
     * `parcial` no está aquí a propósito: ver el comentario de la clase.
     *
     * @var list<string>
     */
    public const ESTADOS_QUE_VENCEN = [
        Ticket::ESTADO_GENERADO,
        Ticket::ESTADO_EN_ALISTAMIENTO,
        Ticket::ESTADO_LISTO,
    ];

    /**
     * Vence los tickets abiertos hasta `$hasta` inclusive.
     *
     * Devuelve una línea por sede con tickets por vencer, de mayor a menor.
     *
     * @param  CarbonInterface|null  $hasta  Último día que se cierra; por defecto ayer, porque el día en curso nunca se toca
     * @param  Sede|null  $sede  Solo esta sede; si es null, todas
     * @param  bool  $simular  Cuenta sin escribir nada
     * @return list<array{sede_id: int, sede: string, total: int, por_estado: array<string, int>}>
     */
    public function handle(?CarbonInterface $hasta = null, ?Sede $sede = null, bool $simular = false): array
    {
        $hasta = ($hasta ?? now()->subDay())->toDateString();

        $resumen = $this->resumen($hasta, $sede);

        if ($simular || $resumen === []) {
            return $resumen;
        }

        foreach ($resumen as $linea) {
            DB::transaction(function () use ($linea, $hasta): void {
                $this->porVencer($hasta)
                    ->where('sede_id', $linea['sede_id'])
                    ->update([
                        'estado' => Ticket::ESTADO_VENCIDO,
                        'cerrado_en' => now(),
                    ]);

                $this->auditar($linea, $hasta);
            });
        }

        return $resumen;
    }

    /**
     * Cuántos van a vencer, por sede y por estado. Es lo que imprime `--simular`.
     *
     * @return list<array{sede_id: int, sede: string, total: int, por_estado: array<string, int>}>
     */
    public function resumen(string $hasta, ?Sede $sede = null): array
    {
        $conteos = $this->porVencer($hasta)
            ->when($sede !== null, fn (Builder $q) => $q->where('sede_id', $sede->getKey()))
            ->toBase()
            ->selectRaw('sede_id, estado, COUNT(*) as total')
            ->groupBy('sede_id', 'estado')
            ->get();

        if ($conteos->isEmpty()) {
            return [];
        }

        $nombres = Sede::query()
            ->whereIn('id', $conteos->pluck('sede_id')->unique()->all())
            ->pluck('nombre', 'id');

        return $conteos
            ->groupBy('sede_id')
            ->map(fn ($filas, $sedeId): array => [
                'sede_id' => (int) $sedeId,
                'sede' => (string) ($nombres[$sedeId] ?? "Sede #{$sedeId}"),
                'total' => (int) $filas->sum('total'),
                'por_estado' => $filas->pluck('total', 'estado')
                    ->map(fn ($total): int => (int) $total)
                    ->all(),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * Los tickets abiertos de días ya pasados.
     *
     * @return Builder<Ticket>
     */
    private function porVencer(string $hasta): Builder
    {
        return Ticket::query()
            ->whereDate('fecha', '<=', $hasta)
            ->whereIn('estado', self::ESTADOS_QUE_VENCEN);
    }

    /**
     * @param  array{sede_id: int, sede: string, total: int, por_estado: array<string, int>}  $linea
     */
    private function auditar(array $linea, string $hasta): void
    {
        $desglose = [];

        foreach ($linea['por_estado'] as $estado => $cuantos) {
            $etiqueta = Ticket::ESTADOS[$estado] ?? $estado;
            $desglose[$etiqueta] = [
                $cuantos.' '.($cuantos === 1 ? 'ticket' : 'tickets'),
                'vencido'.($cuantos === 1 ? '' : 's'),
            ];
        }

        Auditoria::registrar(
            accion: Auditoria::ACCION_CERRO_DIA,
            descripcion: "Cerró el día hasta {$hasta} en {$linea['sede']}: {$linea['total']} "
                .($linea['total'] === 1 ? 'ticket vencido' : 'tickets vencidos'),
            entidadTipo: 'ticket',
            cambios: $desglose,
            sedeId: $linea['sede_id'],
        );
    }
}
