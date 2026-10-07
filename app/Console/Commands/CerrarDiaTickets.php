<?php

namespace App\Console\Commands;

use App\Models\Sede;
use App\Models\Ticket;
use App\Services\Tickets\CerrarElDia;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cierra el día: vence los tickets que nadie alcanzó a atender.
 *
 * Se puede correr a cualquier hora y las veces que haga falta: solo mira días
 * ya pasados, así que repetirlo no cambia nada. El detalle de qué vence y qué
 * no está en `App\Services\Tickets\CerrarElDia`.
 */
class CerrarDiaTickets extends Command
{
    protected $signature = 'tickets:cerrar-dia
        {--fecha= : Último día que se cierra (YYYY-MM-DD). Por defecto ayer}
        {--sede= : Código de la sede (LA30, BIC…). Por defecto todas}
        {--simular : Muestra qué vencería sin tocar nada}';

    protected $description = 'Vence los tickets de días pasados que nadie alcanzó a atender';

    public function handle(CerrarElDia $cierre): int
    {
        try {
            $hasta = $this->fecha();
        } catch (Throwable) {
            $this->error('La fecha debe venir como YYYY-MM-DD. Ejemplo: --fecha=2026-10-04');

            return self::FAILURE;
        }

        if ($hasta->isToday() || $hasta->isFuture()) {
            $this->error('El día en curso no se cierra: todavía hay pacientes por atender.');

            return self::FAILURE;
        }

        $sede = null;

        if ($codigo = $this->option('sede')) {
            $sede = Sede::query()->where('codigo', strtoupper((string) $codigo))->first();

            if ($sede === null) {
                $this->error("No hay ninguna sede con el código «{$codigo}».");

                return self::FAILURE;
            }
        }

        $simular = (bool) $this->option('simular');
        $resumen = $cierre->handle($hasta, $sede, $simular);

        if ($resumen === []) {
            $this->info("No quedó ningún ticket abierto hasta el {$hasta->format('d/m/Y')}.");

            return self::SUCCESS;
        }

        $this->table(
            ['Sede', 'Total', 'Desglose'],
            array_map(fn (array $linea): array => [
                $linea['sede'],
                $linea['total'],
                $this->desglose($linea['por_estado']),
            ], $resumen),
        );

        $total = array_sum(array_column($resumen, 'total'));

        $simular
            ? $this->warn("Simulación: {$total} se vencerían al cerrar hasta el {$hasta->format('d/m/Y')}.")
            : $this->info("Cierre hasta el {$hasta->format('d/m/Y')}: {$total} tickets vencidos.");

        return self::SUCCESS;
    }

    private function fecha(): CarbonImmutable
    {
        $fecha = $this->option('fecha');

        return $fecha
            ? CarbonImmutable::createFromFormat('Y-m-d', (string) $fecha)->startOfDay()
            : CarbonImmutable::yesterday();
    }

    /**
     * @param  array<string, int>  $porEstado
     */
    private function desglose(array $porEstado): string
    {
        $partes = [];

        foreach ($porEstado as $estado => $cuantos) {
            $partes[] = $cuantos.' '.(Ticket::ESTADOS[$estado] ?? $estado);
        }

        return implode(', ', $partes);
    }
}
