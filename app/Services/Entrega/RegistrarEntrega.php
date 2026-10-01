<?php

namespace App\Services\Entrega;

use App\Contracts\Domina\DominaClientInterface;
use App\Contracts\Ticket\Dto\TicketDto;
use App\Models\DomicilioEnvio;
use App\Models\DomicilioEstadoHistorial;
use App\Models\Entrega;
use App\Models\EntregaItem;
use App\Models\Paciente;
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
    ) {}

    /**
     * @param  array{
     *     tipo: string,
     *     observaciones?: ?string,
     *     receptor_nombre?: ?string,
     *     receptor_documento?: ?string,
     *     receptor_parentesco?: ?string,
     *     firma_contenido?: ?string,
     *     items: list<array{ticket_item_id: string, codigo: string, nombre: string, cantidad_solicitada: float|int, unidad: string, cantidad_entregada: float|int, resultado: string, motivo?: ?string}>
     * }  $datos
     */
    public function handle(TicketDto $ticket, User $usuario, array $datos): Entrega
    {
        if (! $ticket->listoParaEntrega()) {
            throw new InvalidArgumentException('El ticket no está listo para entrega.');
        }

        if (! in_array($datos['tipo'], [Entrega::TIPO_PRESENCIAL, Entrega::TIPO_DOMICILIO], true)) {
            throw new InvalidArgumentException('Tipo de entrega no válido.');
        }

        if ($datos['tipo'] === Entrega::TIPO_PRESENCIAL && blank($datos['firma_contenido'] ?? null)) {
            throw new InvalidArgumentException('La entrega presencial requiere firma del receptor.');
        }

        if ($datos['tipo'] === Entrega::TIPO_DOMICILIO && ! $ticket->paciente->contactoConfirmado && blank($ticket->paciente->direccion)) {
            throw new InvalidArgumentException('No hay dirección confirmada para domicilio.');
        }

        return DB::transaction(function () use ($ticket, $usuario, $datos): Entrega {
            $pacienteId = $ticket->paciente->id
                ?? Paciente::query()
                    ->where('tipo_documento', $ticket->paciente->tipoDocumento)
                    ->where('numero_documento', $ticket->paciente->numeroDocumento)
                    ->value('id');

            $entrega = Entrega::create([
                'ticket_numero' => $ticket->numero,
                'paciente_id' => $pacienteId,
                'sede_id' => $usuario->sede_id,
                'usuario_id' => $usuario->id,
                'tipo' => $datos['tipo'],
                'estado' => Entrega::ESTADO_EN_PROCESO,
                'receptor_nombre' => $datos['receptor_nombre'] ?? $ticket->paciente->nombreCompleto,
                'receptor_documento' => $datos['receptor_documento'] ?? $ticket->paciente->numeroDocumento,
                'receptor_parentesco' => $datos['receptor_parentesco'] ?? 'Paciente',
                'observaciones' => $datos['observaciones'] ?? null,
                'facturacion_estado' => Entrega::FACTURACION_PENDIENTE,
            ]);

            foreach ($datos['items'] as $item) {
                EntregaItem::create([
                    'entrega_id' => $entrega->id,
                    'ticket_item_id' => $item['ticket_item_id'],
                    'codigo' => $item['codigo'],
                    'nombre' => $item['nombre'],
                    'cantidad_solicitada' => $item['cantidad_solicitada'],
                    'cantidad_entregada' => $item['cantidad_entregada'],
                    'unidad' => $item['unidad'],
                    'resultado' => $item['resultado'],
                    'motivo' => $item['motivo'] ?? null,
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
                'nota' => $nota,
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

    private function guardarFirma(Entrega $entrega, string $contenido): string
    {
        // Acepta data URL base64 (canvas) o texto marcador de prueba.
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
