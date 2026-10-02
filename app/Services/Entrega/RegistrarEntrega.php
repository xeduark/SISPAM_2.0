<?php

namespace App\Services\Entrega;

use App\Contracts\Domina\DominaClientInterface;
use App\Contracts\Ticket\Dto\TicketDto;
use App\Models\DomicilioEnvio;
use App\Models\DomicilioEstadoHistorial;
use App\Models\Entrega;
use App\Models\EntregaItem;
use App\Models\Paciente;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Registra una atención de entrega a partir de un ticket consultado.
 */
class RegistrarEntrega
{
    public function __construct(
        private DominaClientInterface $domina,
        private SaldoTicket $saldo,
    ) {}

    /**
     * @param  array{
     *     tipo: string,
     *     sede_id?: int|null,
     *     observaciones?: ?string,
     *     receptor_nombre?: ?string,
     *     receptor_documento?: ?string,
     *     receptor_parentesco?: ?string,
     *     firma_contenido?: ?string,
     *     items: list<array{ticket_item_id: string, codigo: string, nombre: string, cantidad_solicitada: float|int, unidad: string, cantidad_entregada: float|int, resultado?: string, motivo?: ?string}>
     * }  $datos
     */
    public function handle(TicketDto $ticket, User $usuario, array $datos): Entrega
    {
        if (! $ticket->listoParaEntrega()) {
            throw new InvalidArgumentException('El ticket no está listo para entrega.');
        }

        if ($this->saldo->ticketCompletamenteDispensado($ticket)) {
            throw new InvalidArgumentException('Este ticket ya fue dispensado completamente.');
        }

        if (! in_array($datos['tipo'], [Entrega::TIPO_PRESENCIAL, Entrega::TIPO_DOMICILIO], true)) {
            throw new InvalidArgumentException('Tipo de entrega no válido.');
        }

        if ($datos['tipo'] === Entrega::TIPO_PRESENCIAL) {
            if (blank($datos['receptor_nombre'] ?? null) || blank($datos['receptor_documento'] ?? null)) {
                throw new InvalidArgumentException('La entrega presencial requiere los datos de quien recibe.');
            }
            if (blank($datos['firma_contenido'] ?? null)) {
                throw new InvalidArgumentException('La entrega presencial requiere firma del receptor.');
            }
        }

        if ($datos['tipo'] === Entrega::TIPO_DOMICILIO && ! $ticket->paciente->contactoConfirmado && blank($ticket->paciente->direccion)) {
            throw new InvalidArgumentException('No hay dirección confirmada para domicilio.');
        }

        if (($datos['items'] ?? []) === []) {
            throw new InvalidArgumentException('La entrega debe incluir al menos un medicamento.');
        }

        $sedeId = (int) ($datos['sede_id'] ?? $usuario->sede_id);
        if ($sedeId <= 0 || ! Sede::query()->whereKey($sedeId)->where('activa', true)->exists()) {
            throw new InvalidArgumentException('Debes indicar una sede de atención activa.');
        }

        $originales = collect($ticket->items)->keyBy(fn ($i) => (string) $i->id);
        $itemsNormalizados = [];

        foreach ($datos['items'] as $item) {
            $idLinea = (string) $item['ticket_item_id'];
            $original = $originales->get($idLinea);
            $cantidadTicket = $original ? (float) $original->cantidad : (float) ($item['cantidad_solicitada'] ?? 0);
            $pendienteActual = $this->saldo->pendienteDeLinea($ticket->numero, $idLinea, $cantidadTicket);

            if ($pendienteActual <= 0) {
                throw new InvalidArgumentException(
                    '«'.($item['nombre'] ?? $idLinea).'» ya no tiene cantidad pendiente por entregar.'
                );
            }

            // En reatención, cantidad_solicitada del formulario es el saldo pendiente.
            $item['cantidad_solicitada'] = $pendienteActual;
            $normalizado = $this->normalizarItem($item);

            if ($normalizado['cantidad_entregada'] > $pendienteActual) {
                throw new InvalidArgumentException(
                    '«'.$normalizado['nombre'].'»: no se puede entregar más de lo pendiente ('.$pendienteActual.').'
                );
            }

            $itemsNormalizados[] = $normalizado;
        }

        return DB::transaction(function () use ($ticket, $usuario, $datos, $itemsNormalizados, $sedeId): Entrega {
            $pacienteId = $ticket->paciente->id
                ?? Paciente::query()
                    ->where('tipo_documento', $ticket->paciente->tipoDocumento)
                    ->where('numero_documento', $ticket->paciente->numeroDocumento)
                    ->value('id');

            $entrega = Entrega::create([
                'ticket_numero' => $ticket->numero,
                'paciente_id' => $pacienteId,
                'sede_id' => $sedeId,
                'usuario_id' => $usuario->id,
                'tipo' => $datos['tipo'],
                'estado' => Entrega::ESTADO_EN_PROCESO,
                'receptor_nombre' => $datos['tipo'] === Entrega::TIPO_PRESENCIAL
                    ? ($datos['receptor_nombre'] ?? null)
                    : ($ticket->paciente->nombreCompleto),
                'receptor_documento' => $datos['tipo'] === Entrega::TIPO_PRESENCIAL
                    ? ($datos['receptor_documento'] ?? null)
                    : ($ticket->paciente->numeroDocumento),
                'receptor_parentesco' => $datos['tipo'] === Entrega::TIPO_PRESENCIAL
                    ? ($datos['receptor_parentesco'] ?? 'Paciente')
                    : 'Domicilio',
                'observaciones' => $datos['observaciones'] ?? null,
                'facturacion_estado' => Entrega::FACTURACION_PENDIENTE,
            ]);

            foreach ($itemsNormalizados as $item) {
                EntregaItem::create([
                    'entrega_id' => $entrega->id,
                    'ticket_item_id' => $item['ticket_item_id'],
                    'codigo' => $item['codigo'],
                    'nombre' => $item['nombre'],
                    'cantidad_solicitada' => $item['cantidad_solicitada'],
                    'cantidad_entregada' => $item['cantidad_entregada'],
                    'cantidad_pendiente' => $item['cantidad_pendiente'],
                    'unidad' => $item['unidad'],
                    'resultado' => $item['resultado'],
                    'motivo' => $item['motivo'],
                ]);
            }

            if ($datos['tipo'] === Entrega::TIPO_PRESENCIAL) {
                $ruta = $this->guardarFirma($entrega, (string) $datos['firma_contenido']);
                $entrega->update(['firma_path' => $ruta]);
            }

            if ($datos['tipo'] === Entrega::TIPO_DOMICILIO) {
                $envio = DomicilioEnvio::create([
                    'entrega_id' => $entrega->id,
                    'telefono' => $ticket->paciente->telefonoMovil,
                    'direccion' => $ticket->paciente->direccion,
                    'barrio' => $ticket->paciente->barrio,
                    'ciudad' => $ticket->paciente->ciudad,
                    'indicaciones_entrega' => $ticket->paciente->indicacionesEntrega,
                    'estado' => DomicilioEnvio::ESTADO_PENDIENTE_ENVIO,
                ]);

                DomicilioEstadoHistorial::create([
                    'domicilio_envio_id' => $envio->id,
                    'estado_anterior' => null,
                    'estado_nuevo' => DomicilioEnvio::ESTADO_PENDIENTE_ENVIO,
                    'usuario_id' => $usuario->id,
                    'nota' => 'Envío creado desde atención de entrega',
                ]);
            }

            $entrega->recalcularEstado();

            return $entrega->fresh(['items', 'domicilioEnvio', 'paciente']);
        });
    }

    public function cambiarEstadoDomicilio(
        DomicilioEnvio $envio,
        string $estadoNuevo,
        User $usuario,
        ?string $nota = null,
        ?string $novedadDetalle = null,
    ): DomicilioEnvio {
        if (! array_key_exists($estadoNuevo, DomicilioEnvio::estados())) {
            throw new InvalidArgumentException('Estado de domicilio no válido.');
        }

        if (! $envio->puedePasarA($estadoNuevo)) {
            $actual = DomicilioEnvio::estados()[$envio->estado] ?? $envio->estado;
            $nuevo = DomicilioEnvio::estados()[$estadoNuevo] ?? $estadoNuevo;
            throw new InvalidArgumentException("No se puede pasar de «{$actual}» a «{$nuevo}».");
        }

        if (in_array($estadoNuevo, [DomicilioEnvio::ESTADO_NOVEDAD, DomicilioEnvio::ESTADO_NO_ENTREGADO], true)
            && blank($novedadDetalle) && blank($nota)) {
            throw new InvalidArgumentException('Indica la novedad u observación del cambio de estado.');
        }

        return DB::transaction(function () use ($envio, $estadoNuevo, $usuario, $nota, $novedadDetalle): DomicilioEnvio {
            $anterior = $envio->estado;

            $envio->estado = $estadoNuevo;
            if ($novedadDetalle !== null) {
                $envio->novedad_detalle = $novedadDetalle;
            }

            if ($estadoNuevo === DomicilioEnvio::ESTADO_ENVIADO_DOMINA && blank($envio->referencia_externa)) {
                $envio->referencia_externa = $this->domina->enviarEnvio($envio);
            }

            $envio->save();

            DomicilioEstadoHistorial::create([
                'domicilio_envio_id' => $envio->id,
                'estado_anterior' => $anterior,
                'estado_nuevo' => $estadoNuevo,
                'usuario_id' => $usuario->id,
                'nota' => $nota ?: $novedadDetalle,
            ]);

            if ($estadoNuevo === DomicilioEnvio::ESTADO_ENTREGADO) {
                $envio->entrega->update(['estado' => Entrega::ESTADO_COMPLETADA]);
            }

            if (in_array($estadoNuevo, [DomicilioEnvio::ESTADO_NO_ENTREGADO, DomicilioEnvio::ESTADO_NOVEDAD], true)) {
                $envio->entrega->update(['estado' => Entrega::ESTADO_PARCIAL]);
            }

            return $envio->fresh(['historial', 'entrega']);
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{
     *     ticket_item_id: string,
     *     codigo: string,
     *     nombre: string,
     *     cantidad_solicitada: float,
     *     cantidad_entregada: float,
     *     cantidad_pendiente: float,
     *     unidad: string,
     *     resultado: string,
     *     motivo: ?string
     * }
     */
    public function normalizarItem(array $item): array
    {
        $solicitada = (float) ($item['cantidad_solicitada'] ?? 0);
        $entregada = (float) ($item['cantidad_entregada'] ?? 0);
        $nombre = (string) ($item['nombre'] ?? $item['codigo'] ?? 'medicamento');

        if ($solicitada <= 0) {
            throw new InvalidArgumentException("«{$nombre}»: la cantidad solicitada debe ser mayor que cero.");
        }

        if ($entregada < 0) {
            throw new InvalidArgumentException("«{$nombre}»: la cantidad entregada no puede ser negativa.");
        }

        if ($entregada > $solicitada) {
            throw new InvalidArgumentException("«{$nombre}»: no se puede entregar más de lo solicitado ({$solicitada}).");
        }

        $pendiente = round($solicitada - $entregada, 2);
        $motivo = filled($item['motivo'] ?? null) ? (string) $item['motivo'] : null;
        $resultadoPedido = (string) ($item['resultado'] ?? '');

        if ($pendiente <= 0) {
            $resultado = EntregaItem::RESULTADO_ENTREGADO;
            $motivo = null;
        } elseif ($entregada > 0) {
            $resultado = EntregaItem::RESULTADO_PARCIAL;
            if (blank($motivo)) {
                throw new InvalidArgumentException("«{$nombre}»: indica el motivo de la cantidad pendiente ({$pendiente}).");
            }
        } else {
            // Cero entregado: clasifica el motivo (faltante de stock vs aplazamiento).
            // La cantidad pendiente siempre queda en cantidad_pendiente.
            $resultado = in_array($resultadoPedido, [
                EntregaItem::RESULTADO_FALTANTE,
                EntregaItem::RESULTADO_PENDIENTE,
            ], true) ? $resultadoPedido : EntregaItem::RESULTADO_FALTANTE;

            if (blank($motivo)) {
                throw new InvalidArgumentException("«{$nombre}»: indica el motivo por el cual no se entrega ahora.");
            }
        }

        return [
            'ticket_item_id' => (string) $item['ticket_item_id'],
            'codigo' => (string) $item['codigo'],
            'nombre' => (string) $item['nombre'],
            'cantidad_solicitada' => $solicitada,
            'cantidad_entregada' => $entregada,
            'cantidad_pendiente' => $pendiente,
            'unidad' => (string) ($item['unidad'] ?? 'UND'),
            'resultado' => $resultado,
            'motivo' => $motivo,
        ];
    }

    private function guardarFirma(Entrega $entrega, string $contenido): string
    {
        $binario = $contenido;
        if (str_starts_with($contenido, 'data:image')) {
            $partes = explode(',', $contenido, 2);
            $binario = base64_decode($partes[1] ?? '', true) ?: $contenido;
        }

        $ruta = "soportes/firmas-entrega/{$entrega->id}.png";
        Storage::disk('local')->put($ruta, $binario);

        return $ruta;
    }
}
