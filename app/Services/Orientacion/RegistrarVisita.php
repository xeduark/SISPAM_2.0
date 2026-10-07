<?php

namespace App\Services\Orientacion;

use App\Models\Paciente;
use App\Models\Soporte;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\GenerarTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Registra de una sola vez todo lo que pasa cuando el orientador recibe a un
 * paciente: lo crea o lo actualiza con lo que respondió Savia, guarda el
 * contacto que confirmó de viva voz, cuelga las fórmulas y abre el ticket.
 *
 * Desde que el asistente de Pacientes dejó de abrir visitas, **este es el
 * único sitio donde nace una**. La pantalla avisa si hay una visita viva
 * (`VisitaAbierta`); además, la misma ruta de archivo (`orden_medica`) no
 * genera otro soporte ni otro turno: cada upload nuevo de Filament usa un
 * ULID distinto, así que reenviar la misma ruta es reenvío accidental.
 */
class RegistrarVisita
{
    public function __construct(
        private GenerarTicket $generarTicket,
    ) {}

    /**
     * @param  array<string, mixed>  $atributos  Columnas que llena Savia (`Paciente::CAMPOS_SAVIA`)
     * @param  array<string, string|null>  $contacto  Lo que el orientador confirmó con el paciente
     * @param  list<string>  $ordenes  Rutas de las fórmulas, ya guardadas en el disco privado
     * @param  array{prioridad?: string, motivo_prioridad?: ?string}  $datosTicket
     * @param  Ticket|null  $visitaExistente  Para sumar hojas a una visita abierta en vez de abrir otra
     */
    public function handle(
        array $atributos,
        array $contacto,
        array $ordenes,
        array $datosTicket,
        User $usuario,
        ?Ticket $visitaExistente = null,
    ): ResultadoVisita {
        return DB::transaction(function () use ($atributos, $contacto, $ordenes, $datosTicket, $usuario, $visitaExistente): ResultadoVisita {
            $paciente = $this->guardarPaciente($atributos, $contacto, $usuario);

            // Dos Guardar casi a la vez sobre el mismo paciente no deben abrir
            // dos visitas con la misma fórmula.
            Paciente::query()->whereKey($paciente->getKey())->lockForUpdate()->first();

            [$rutasNuevas, $soportesYaProcesados] = $this->separarOrdenesNuevas($ordenes);

            if ($visitaExistente !== null) {
                $this->comprobarQueLaVisitaEsDelPaciente($visitaExistente, $paciente, $usuario);

                $ticket = $visitaExistente;
                $motivo = null;
                $desde = (int) $ticket->soportes()->max('pagina');
                $turnoNuevo = false;
            } elseif ($rutasNuevas === [] && $soportesYaProcesados !== []) {
                // Todas las rutas ya estaban: no se consume otro turno.
                $ticket = $this->ticketDeSoportesExistentes($soportesYaProcesados);
                $motivo = null;
                $desde = 0;
                $turnoNuevo = false;
            } else {
                [$ticket, $motivo] = $this->abrirTicket($paciente, $datosTicket, $usuario);
                $desde = 0;
                $turnoNuevo = true;
            }

            $creadas = 0;

            foreach (array_values($rutasNuevas) as $posicion => $ruta) {
                $paciente->soportes()->create([
                    'ticket_id' => $ticket?->getKey(),
                    'orden_medica' => $ruta,
                    'pagina' => $desde + $posicion + 1,
                    'mime' => $this->mimeDe($ruta),
                    'cargado_por' => $usuario->getKey(),
                ]);
                $creadas++;
            }

            return new ResultadoVisita(
                paciente: $paciente,
                ticket: $ticket,
                pacienteNuevo: $paciente->wasRecentlyCreated,
                ordenesGuardadas: $creadas,
                motivoSinTicket: $motivo,
                turnoNuevo: $turnoNuevo && $creadas > 0,
            );
        });
    }

    /**
     * Le da turno a una fórmula que quedó guardada sin ticket.
     *
     * Pasa cuando la sede no tenía colas activas el día que se cargó. Las
     * fotos ya están en el disco, así que no hay que volver a tomarlas: solo
     * se abre el ticket y se le cuelgan las hojas, renumeradas de 1 en
     * adelante por si venían de varias cargas del mismo día.
     *
     * @param  array{prioridad?: string, motivo_prioridad?: ?string}  $datosTicket
     */
    public function completarFormulaSinTurno(Paciente $paciente, array $datosTicket, User $usuario): ResultadoVisita
    {
        return DB::transaction(function () use ($paciente, $datosTicket, $usuario): ResultadoVisita {
            Paciente::query()->whereKey($paciente->getKey())->lockForUpdate()->first();

            $hojas = $paciente->formulasSinTurno();

            if ($hojas->isEmpty()) {
                throw new InvalidArgumentException('Ese paciente ya no tiene fórmulas sin turno.');
            }

            [$ticket, $motivo] = $this->abrirTicket($paciente, $datosTicket, $usuario);

            if ($ticket !== null) {
                foreach ($hojas->values() as $posicion => $hoja) {
                    $hoja->update([
                        'ticket_id' => $ticket->getKey(),
                        'pagina' => $posicion + 1,
                    ]);
                }
            }

            return new ResultadoVisita(
                paciente: $paciente,
                ticket: $ticket,
                pacienteNuevo: false,
                ordenesGuardadas: $hojas->count(),
                motivoSinTicket: $motivo,
            );
        });
    }

    /**
     * Separa rutas nuevas de las que ya tienen un Soporte (misma orden en disco).
     *
     * @param  list<string>  $ordenes
     * @return array{0: list<string>, 1: list<Soporte>}
     */
    private function separarOrdenesNuevas(array $ordenes): array
    {
        $rutasNuevas = [];
        $existentes = [];
        $vistas = [];

        foreach ($ordenes as $ruta) {
            if (! is_string($ruta) || $ruta === '' || isset($vistas[$ruta])) {
                continue;
            }

            $vistas[$ruta] = true;

            $soporte = Soporte::query()
                ->where('orden_medica', $ruta)
                ->lockForUpdate()
                ->first();

            if ($soporte) {
                $existentes[] = $soporte;
            } else {
                $rutasNuevas[] = $ruta;
            }
        }

        return [$rutasNuevas, $existentes];
    }

    /**
     * @param  list<Soporte>  $soportes
     */
    private function ticketDeSoportesExistentes(array $soportes): ?Ticket
    {
        foreach ($soportes as $soporte) {
            if ($soporte->ticket_id) {
                return Ticket::query()->find($soporte->ticket_id);
            }
        }

        return null;
    }

    /**
     * Nadie suma hojas a la visita de otro paciente ni de otra sede.
     *
     * La pantalla solo ofrece la visita que encontró, pero el id viaja en el
     * estado de Livewire: cambiarlo a mano colgaría una fórmula del ticket
     * equivocado, y eso es mezclar datos de salud de dos personas.
     */
    private function comprobarQueLaVisitaEsDelPaciente(Ticket $visita, Paciente $paciente, User $usuario): void
    {
        if ((int) $visita->paciente_id !== (int) $paciente->getKey()) {
            throw new InvalidArgumentException('Esa visita es de otro paciente.');
        }

        if ((int) $visita->sede_id !== (int) $usuario->sede_id && ! $usuario->es_administrador) {
            throw new InvalidArgumentException('Esa visita es de otra sede.');
        }
    }

    /**
     * Crea o actualiza, nunca duplica: la llave es tipo + número de documento,
     * igual que en el asistente de Pacientes.
     *
     * El contacto se escribe **aparte** de los atributos de Savia y solo con
     * las columnas de `CAMPOS_CONTACTO`, que es lo que mantiene la regla de
     * quién manda sobre cada dato: ninguna consulta puede pisar el teléfono y
     * la dirección que el personal confirmó con el paciente.
     *
     * @param  array<string, mixed>  $atributos
     * @param  array<string, string|null>  $contacto
     */
    private function guardarPaciente(array $atributos, array $contacto, User $usuario): Paciente
    {
        $paciente = Paciente::firstOrNew([
            'tipo_documento' => $atributos['tipo_documento'] ?? null,
            'numero_documento' => $atributos['numero_documento'] ?? null,
        ]);

        $paciente->fill($atributos);

        foreach (Paciente::CAMPOS_CONTACTO as $columna) {
            if (array_key_exists($columna, $contacto)) {
                $paciente->{$columna} = $contacto[$columna];
            }
        }

        // Queda constancia de quién confirmó el contacto con el paciente y cuándo.
        $paciente->contacto_confirmado_at = now();
        $paciente->contacto_confirmado_por = $usuario->getKey();

        $paciente->save();

        return $paciente;
    }

    /**
     * El ticket de la visita, o el motivo en español por el que no se pudo.
     *
     * @param  array{prioridad?: string, motivo_prioridad?: ?string}  $datosTicket
     * @return array{0: ?Ticket, 1: ?string}
     */
    private function abrirTicket(Paciente $paciente, array $datosTicket, User $usuario): array
    {
        // `users.sede_id` es NOT NULL, así que en la práctica siempre hay sede.
        // La guarda queda porque la relación se tipa como `?Sede` y un null
        // aquí reventaría con un TypeError en vez de avisar en español.
        if ($usuario->sede === null) {
            return [null, 'Tu usuario no tiene una sede asignada, así que no se pudo generar el turno. Pídele a un administrador que te asigne una en Administración → Usuarios.'];
        }

        try {
            return [
                $this->generarTicket->handle($paciente, $usuario->sede, $usuario, $datosTicket),
                null,
            ];
        } catch (RuntimeException $e) {
            // La sede no está configurada. Se sigue adelante a propósito: la
            // orden médica y el contacto confirmado valen más que el turno.
            return [null, $e->getMessage()];
        }
    }

    /**
     * El tipo del archivo, preguntándoselo al disco y no al navegador.
     *
     * Sirve para que después la galería sepa si abre una imagen o un PDF sin
     * tener que tocar el archivo. Si el disco no lo puede decir queda en null,
     * que es lo mismo que tienen los soportes anteriores a esta columna, y
     * `Soporte::esPdf()` cae de vuelta a la extensión.
     */
    private function mimeDe(string $ruta): ?string
    {
        try {
            return Storage::disk('local')->mimeType($ruta) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
