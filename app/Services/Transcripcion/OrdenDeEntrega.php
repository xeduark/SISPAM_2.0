<?php

namespace App\Services\Transcripcion;

use App\Models\Ticket;
use App\Models\Transcripcion;
use App\Services\Inventario\InventarioApi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Arma la «Orden de Dispensación & Alistamiento» de un ticket con sus
 * transcripciones confirmadas. Corrige el formato del sistema nativo:
 * - Cada medicamento con su fórmula real (el nativo marcaba todo «Fór. #1»).
 * - Posología de verdad, no la concentración.
 * - Lo que no alcanza en la sede va aparte, como pendiente de domicilio.
 * - Sin total de «unidades»: sumar tabletas con inhaladores no dice nada.
 */
class OrdenDeEntrega
{
    public function __construct(
        private InventarioApi $inventario,
    ) {}

    /** @return array<string, mixed> los datos de la vista `ordenes.entrega` */
    public function paraTicket(Ticket $ticket): array
    {
        $todas = $this->transcripciones($ticket);

        $confirmadas = $todas->where('estado', Transcripcion::ESTADO_CONFIRMADA);

        // Fórmulas numeradas de corrido en todo el ticket: (transcripción, n° en la imagen) → n° en la orden.
        $formulas = [];
        $numero = [];
        foreach ($confirmadas as $t) {
            foreach ($t->formulas() as $f) {
                $numero[$t->id][$f['numero']] = count($formulas) + 1;
                $formulas[] = $f + ['n' => count($formulas) + 1];
            }
        }

        // Lo de una fórmula que no se dispensa no se alista: va aparte, con su motivo.
        $lineas = $confirmadas->flatMap(fn (Transcripcion $t) => $t->items->reject(fn ($item) => in_array($item->formula, $t->formulasRechazadas(), true))->map(fn ($item) => [
            'formula' => $numero[$t->id][$item->formula] ?? null,
            'codigo' => $item->codigo_inventario,
            'producto' => $item->nombre_inventario ?: $item->texto_prescrito,
            'prescrito' => $item->texto_prescrito,
            'posologia' => $item->posologia,
            'meses' => $item->meses,
            'entrega_mes' => $item->entrega_mes,
            'cantidad' => (int) $item->cantidad_mes,
            'saldo' => $item->cantidad_total > 0 ? max(0, $item->cantidad_total - $item->cantidad_mes * max(1, (int) $item->entrega_mes)) : null,
        ]))->sortBy([['formula', 'asc'], ['producto', 'asc']])->values();

        [$ventanilla, $pendientes, $stockConsultado] = $this->separarPorStock($lineas, (string) $ticket->sede?->codigo);

        return [
            'ticket' => $ticket,
            'formulas' => $formulas,
            'noDispensados' => $this->noDispensados($confirmadas, $numero),
            'ventanilla' => $ventanilla,
            'pendientes' => $pendientes,
            'stockConsultado' => $stockConsultado,
            'sinConfirmar' => $todas->count() - $confirmadas->count(),
            // La orden es la última versión: si alguna fórmula se rectificó, se nota en el encabezado.
            'version' => (int) $confirmadas->max('version'),
            'validadoPor' => $confirmadas->pluck('confirmadaPor.nombre')->filter()->unique()->implode(', '),
        ];
    }

    /**
     * Medicamentos de las fórmulas marcadas «no se dispensa», con el motivo que verá el paciente.
     * $numero: (transcripción, n° en la imagen) → n° en la orden; sin él se usa el de la imagen.
     *
     * @return Collection<int, array{formula: int, ips: ?string, prescrito: string, motivo: string}>
     */
    public function noDispensados(Collection $confirmadas, array $numero = []): Collection
    {
        return $confirmadas->flatMap(function (Transcripcion $t) use ($numero) {
            $formulas = collect($t->formulas())->keyBy('numero');

            return $t->items->filter(fn ($item) => in_array($item->formula, $t->formulasRechazadas(), true))
                ->map(fn ($item) => [
                    'formula' => $numero[$t->id][$item->formula] ?? $item->formula,
                    'ips' => $formulas[$item->formula]['ips'] ?? null,
                    'prescrito' => $item->texto_prescrito,
                    'motivo' => trim((Transcripcion::MOTIVOS_RECHAZO_FORMULA[$formulas[$item->formula]['motivo_rechazo'] ?? ''] ?? 'Sin motivo')
                        .'. '.($formulas[$item->formula]['detalle_rechazo'] ?? ''), '. '),
                ]);
        })->values();
    }

    /** Las transcripciones del ticket (por ticket o por sus soportes). */
    public function transcripciones(Ticket $ticket): Collection
    {
        return Transcripcion::query()
            ->where(fn ($q) => $q->where('ticket_id', $ticket->getKey())
                ->orWhereIn('soporte_id', $ticket->soportes()->select('id')))
            ->with(['items', 'confirmadaPor'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Lo que hay en la sede va a ventanilla; lo que no alcanza, a pendientes (domicilio).
     * Es una foto al imprimir: farmacia confirma al alistar.
     * $cacheado: la previsualización de la pantalla de transcripción se recalcula con cada
     * cambio; ahí el stock se guarda un par de minutos para no llamar a la API en cada tecla.
     * La orden impresa siempre consulta fresco.
     *
     * @param  Collection<int, array{codigo: ?string, cantidad: int}>  $lineas
     * @return array{0: Collection, 1: Collection, 2: bool}
     */
    public function separarPorStock(Collection $lineas, string $sede, bool $cacheado = false): array
    {
        if (! InventarioApi::configurada() || $sede === '' || $lineas->isEmpty()) {
            return [$lineas, collect(), false];
        }

        try {
            $codigos = $lineas->pluck('codigo')->filter()->unique()->sort()->values()->all();
            $stock = $cacheado
                ? Cache::remember('inventario:stock:'.$sede.':'.md5(implode('|', $codigos)), now()->addMinutes(2),
                    fn () => $this->inventario->stock($codigos, $sede))
                : $this->inventario->stock($codigos, $sede);
        } catch (Throwable) {
            return [$lineas, collect(), false];
        }

        $ventanilla = collect();
        $pendientes = collect();

        // Si dos líneas piden el mismo producto, la segunda solo cuenta con lo que dejó la primera.
        foreach ($lineas as $linea) {
            $hay = $stock[$linea['codigo']] ?? 0;
            $entrega = min($hay, $linea['cantidad']);
            $stock[$linea['codigo']] = $hay - $entrega;

            if ($entrega > 0) {
                $ventanilla->push($linea + ['entrega' => $entrega]);
            }
            if ($entrega < $linea['cantidad']) {
                $pendientes->push($linea + ['falta' => $linea['cantidad'] - $entrega]);
            }
        }

        return [$ventanilla, $pendientes, true];
    }
}
