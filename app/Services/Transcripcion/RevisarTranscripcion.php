<?php

namespace App\Services\Transcripcion;

use App\Contracts\Inventario\CatalogoInventarioInterface;
use App\Models\Auditoria;
use App\Models\Entrega;
use App\Models\Transcripcion;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Las reglas de la revisión: quién puede tocar una transcripción, qué se guarda
 * y qué exige confirmar. La pantalla (RevisarTranscripcion) solo llama aquí.
 *
 * Una transcripción la revisa una sola persona a la vez: se «toma» con
 * lockForUpdate, como el llamado de turnos (LlamadorDeTurnos).
 */
class RevisarTranscripcion
{
    public function __construct(
        private CatalogoInventarioInterface $catalogo,
    ) {}

    public function tomar(Transcripcion $transcripcion, User $usuario): Transcripcion
    {
        return DB::transaction(function () use ($transcripcion, $usuario) {
            $t = Transcripcion::lockForUpdate()->findOrFail($transcripcion->getKey());

            if ($t->estado === Transcripcion::ESTADO_EN_REVISION && $t->tomada_por !== $usuario->getKey()) {
                throw new InvalidArgumentException('La está revisando '.($t->tomadaPor?->nombre ?? 'otra persona').'.');
            }
            if (! in_array($t->estado, [Transcripcion::ESTADO_LEIDA, Transcripcion::ESTADO_EN_REVISION], true)) {
                throw new InvalidArgumentException('Esta fórmula no está para revisar ('.Transcripcion::ESTADOS[$t->estado].').');
            }

            $t->update(['estado' => Transcripcion::ESTADO_EN_REVISION, 'tomada_por' => $usuario->getKey(), 'tomada_en' => now()]);

            return $t;
        });
    }

    /** La suelta sin confirmar: vuelve a la cola para que otra la tome. El administrador puede soltar la de otra. */
    public function liberar(Transcripcion $transcripcion, User $usuario): void
    {
        $this->exigirQueLaTenga($transcripcion, $usuario, permitirAdmin: true);
        // Soltarla la dejaría «por revisar» y se perdería que estaba confirmada: se termina confirmando.
        if ($transcripcion->enRectificacion()) {
            throw new InvalidArgumentException('Está en rectificación: termina confirmando los cambios (la orden queda como nueva versión).');
        }
        $transcripcion->update(['estado' => Transcripcion::ESTADO_LEIDA, 'tomada_por' => null, 'tomada_en' => null]);
    }

    /**
     * Guarda lo corregido. Las líneas que cambian quedan con `editado_por`.
     *
     * @param  list<array<string, mixed>>  $formulas
     * @param  list<array<string, mixed>>  $items  con `id` las que ya existían
     */
    public function guardar(Transcripcion $transcripcion, User $usuario, array $formulas, array $items): void
    {
        $this->exigirQueLaTenga($transcripcion, $usuario);

        DB::transaction(function () use ($transcripcion, $usuario, $formulas, $items) {
            $transcripcion->update(['formula' => ['formulas' => array_values($formulas)] + ($transcripcion->formula ?? [])]);

            $existentes = $transcripcion->items()->get()->keyBy('id');
            $conservar = [];

            foreach ($items as $datos) {
                $item = $existentes->get($datos['id'] ?? null) ?? $transcripcion->items()->make();
                $item->fill(Arr::only($datos, [
                    'formula', 'texto_prescrito', 'concentracion', 'posologia', 'codigo_inventario',
                    'cantidad_total', 'duracion_dias', 'meses', 'entrega_mes', 'cantidad_mes', 'revisado',
                ]));

                // Cambió el producto: se recalculan nombre, similitud y alertas contra el inventario.
                if ($item->isDirty('codigo_inventario') || $item->isDirty('texto_prescrito')) {
                    $this->sugerencia($item);
                }
                if ($item->isDirty()) {
                    $item->editado_por = $usuario->getKey();
                }

                $item->save();
                $conservar[] = $item->getKey();
            }

            $transcripcion->items()->whereNotIn('id', $conservar)->delete();
        });
    }

    /**
     * @throws InvalidArgumentException con lo que falta, para mostrarlo tal cual
     */
    public function confirmar(Transcripcion $transcripcion, User $usuario, bool $cedulaRevisada = false): void
    {
        $this->exigirQueLaTenga($transcripcion, $usuario);
        $faltas = $this->faltasParaConfirmar($transcripcion, $cedulaRevisada);

        if ($faltas !== []) {
            throw new InvalidArgumentException(implode("\n", $faltas));
        }

        DB::transaction(function () use ($transcripcion, $usuario) {
            $datos = [
                'estado' => Transcripcion::ESTADO_CONFIRMADA,
                'verificacion_cedula' => $transcripcion->verificacion_cedula === Transcripcion::CEDULA_NO_ENCONTRADA
                    ? Transcripcion::CEDULA_REVISADA
                    : $transcripcion->verificacion_cedula,
                'confirmada_por' => $usuario->getKey(),
                'confirmada_en' => now(),
            ];

            // Cierra una rectificación: nueva versión y el antes → después en la auditoría.
            if ($transcripcion->enRectificacion()) {
                $rect = $transcripcion->formula['rectificacion'];
                Auditoria::registrar(
                    accion: Auditoria::ACCION_RECTIFICO_TRANSCRIPCION,
                    descripcion: "Rectificó la transcripción #{$transcripcion->id} (versión {$transcripcion->version} → ".($transcripcion->version + 1).'): '.$rect['motivo'],
                    entidadTipo: Transcripcion::ETIQUETA_AUDITORIA,
                    entidadId: $transcripcion->id,
                    cambios: ['antes' => $rect['antes'], 'despues' => $this->foto($transcripcion)],
                    usuario: $usuario,
                );
                $datos['version'] = $transcripcion->version + 1;
                $datos['formula'] = collect($transcripcion->formula)->except('rectificacion')->all();
            }

            $transcripcion->update($datos);
        });
    }

    /**
     * Reabre una transcripción confirmada para corregirla (fase 4). Solo mientras no se haya
     * entregado nada del ticket: lo entregado ya salió del inventario y está en el acta firmada.
     */
    public function rectificar(Transcripcion $transcripcion, User $usuario, string $motivo): Transcripcion
    {
        if (! $usuario->puede('transcripcion.rectificar')) {
            throw new InvalidArgumentException('No tienes permiso para rectificar transcripciones confirmadas.');
        }
        if (blank(trim($motivo))) {
            throw new InvalidArgumentException('Escribe por qué se rectifica.');
        }

        return DB::transaction(function () use ($transcripcion, $usuario, $motivo) {
            $t = Transcripcion::lockForUpdate()->findOrFail($transcripcion->getKey());

            if ($t->estado !== Transcripcion::ESTADO_CONFIRMADA) {
                throw new InvalidArgumentException('Solo se rectifica una transcripción confirmada.');
            }
            $numeroTicket = $t->ticket?->numero ?? $t->soporte?->ticket?->numero;
            if ($numeroTicket && Entrega::where('ticket_numero', $numeroTicket)->where('estado', '!=', Entrega::ESTADO_ANULADA)->exists()) {
                throw new InvalidArgumentException('Ya hay una entrega de este ticket: lo entregado no se puede rectificar.');
            }

            $t->update([
                'estado' => Transcripcion::ESTADO_EN_REVISION,
                'tomada_por' => $usuario->getKey(),
                'tomada_en' => now(),
                'formula' => ($t->formula ?? []) + ['rectificacion' => [
                    'motivo' => trim($motivo),
                    'por' => $usuario->getKey(),
                    'antes' => $this->foto($t),
                ]],
            ]);

            return $t;
        });
    }

    /**
     * Lo que cambia la entrega, sin texto clínico: por línea fórmula, producto y cantidades;
     * y qué fórmulas no se dispensan.
     *
     * @return array{lineas: list<array<string, mixed>>, no_se_dispensan: list<int>}
     */
    private function foto(Transcripcion $t): array
    {
        return [
            'lineas' => $t->items()->orderBy('id')->get()
                ->map(fn ($i) => $i->only(['id', 'formula', 'codigo_inventario', 'cantidad_mes', 'meses', 'entrega_mes']))->all(),
            'no_se_dispensan' => $t->formulasRechazadas(),
        ];
    }

    public function rechazar(Transcripcion $transcripcion, User $usuario, string $motivo, ?string $detalle = null): void
    {
        $this->exigirQueLaTenga($transcripcion, $usuario);

        if (! array_key_exists($motivo, Transcripcion::MOTIVOS_RECHAZO)) {
            throw new InvalidArgumentException('Motivo de rechazo no válido.');
        }
        if ($motivo === 'otro' && blank($detalle)) {
            throw new InvalidArgumentException('Explica el motivo del rechazo.');
        }

        $transcripcion->update([
            'estado' => Transcripcion::ESTADO_RECHAZADA,
            'motivo_rechazo' => $motivo,
            'detalle_rechazo' => $detalle,
            'confirmada_por' => $usuario->getKey(),
            'confirmada_en' => now(),
        ]);
    }

    /** @return list<string> lo que impide confirmar, en palabras de la transcriptora */
    public function faltasParaConfirmar(Transcripcion $transcripcion, bool $cedulaRevisada): array
    {
        $faltas = [];
        $formulas = collect($transcripcion->formulas())->keyBy('numero');
        $items = $transcripcion->items()->orderBy('formula')->orderBy('id')->get();

        if ($transcripcion->verificacion_cedula === Transcripcion::CEDULA_NO_ENCONTRADA && ! $cedulaRevisada) {
            $faltas[] = 'La cédula no se encontró en la lectura: confirma en el original que la fórmula es de este paciente.';
        }
        if ($items->isEmpty()) {
            $faltas[] = 'No hay medicamentos. Si la fórmula no se puede leer, recházala.';
        }

        // Una fórmula rechazada no se revisa más: solo debe decir por qué (sale en el acta que firma el paciente).
        $rechazadas = $transcripcion->formulasRechazadas();
        if ($formulas->isNotEmpty() && count($rechazadas) === $formulas->count()) {
            $faltas[] = 'Todas las fórmulas están marcadas como no dispensables: rechaza la transcripción completa.';
        }

        foreach ($formulas as $numero => $f) {
            if (in_array((int) $numero, $rechazadas, true)) {
                if (! array_key_exists($f['motivo_rechazo'] ?? '', Transcripcion::MOTIVOS_RECHAZO_FORMULA)) {
                    $faltas[] = "Fórmula #{$numero}: elige el motivo por el que no se dispensa.";
                } elseif ($f['motivo_rechazo'] === 'otro' && blank($f['detalle_rechazo'] ?? null)) {
                    $faltas[] = "Fórmula #{$numero}: explica el motivo en el detalle.";
                }

                continue;
            }

            $fecha = $f['fecha_expedicion'] ?? null;
            if (blank($fecha)) {
                $faltas[] = "Fórmula #{$numero}: falta la fecha de expedición.";
            } elseif ($fecha > today()->toDateString()) {
                $faltas[] = "Fórmula #{$numero}: la fecha de expedición ({$fecha}) es futura.";
            }
            if (filled($f['vigencia'] ?? null) && $f['vigencia'] < today()->toDateString()) {
                $faltas[] = "Fórmula #{$numero}: venció el {$f['vigencia']}. Si de verdad está vencida, márcala «No se dispensa» con el motivo «Fórmula vencida».";
            }
            if (blank($f['ips'] ?? null) || blank($f['medico']['nombre'] ?? null)) {
                $faltas[] = "Fórmula #{$numero}: faltan la IPS o el médico.";
            }
        }

        foreach ($items as $i => $item) {
            $linea = 'Línea '.($i + 1).' ('.str($item->texto_prescrito)->limit(40).')';

            if (! $formulas->has($item->formula)) {
                $faltas[] = "{$linea}: es de la fórmula #{$item->formula}, que no existe.";
            }
            if (in_array($item->formula, $rechazadas, true)) {
                continue; // no se dispensa: no necesita producto ni cantidades
            }
            if (blank($item->codigo_inventario)) {
                $faltas[] = "{$linea}: elige el producto de bodega.";
            }
            if (! $item->revisado) {
                $faltas[] = "{$linea}: márcala «Revisado» después de compararla con la fórmula original.";
            }
            if (! ($item->cantidad_mes > 0)) {
                $faltas[] = "{$linea}: falta la cantidad a entregar este mes.";
            }
            if (! ($item->meses >= 1) || ! ($item->entrega_mes >= 1) || $item->entrega_mes > $item->meses) {
                $faltas[] = "{$linea}: revisa la entrega n de m (mes {$item->entrega_mes} de {$item->meses}).";
            }
            if ($item->cantidad_total > 0 && $item->cantidad_mes * ($item->entrega_mes ?? 1) > $item->cantidad_total) {
                $faltas[] = "{$linea}: con esta entrega se supera el total prescrito ({$item->cantidad_total}).";
            }
        }

        return $faltas;
    }

    private function sugerencia($item): void
    {
        $prescrito = (string) $item->texto_prescrito;

        if (blank($item->codigo_inventario)) {
            $item->fill(['agrupador' => null, 'nombre_inventario' => null, 'similitud' => null, 'alertas' => InterpretarFormula::alertas($prescrito, null, null)]);

            return;
        }

        // ponytail: busca el producto por su código entre las coincidencias del texto; si la transcriptora
        // eligió uno que no sale ahí, se busca por el código mismo.
        $elegido = collect($this->catalogo->buscar($prescrito, 50))->firstWhere('codigo', $item->codigo_inventario)
            ?? collect($this->catalogo->buscar($item->codigo_inventario, 50))->firstWhere('codigo', $item->codigo_inventario);

        $similitud = $elegido ? InterpretarFormula::similitud($prescrito, $elegido->nombre) : null;
        $item->fill([
            'agrupador' => $elegido?->agrupador,
            'nombre_inventario' => $elegido?->nombre ?? $item->codigo_inventario,
            'similitud' => $similitud,
            'alertas' => InterpretarFormula::alertas($prescrito, $elegido?->nombre, $similitud),
        ]);
    }

    private function exigirQueLaTenga(Transcripcion $transcripcion, User $usuario, bool $permitirAdmin = false): void
    {
        $esSuya = $transcripcion->estado === Transcripcion::ESTADO_EN_REVISION
            && $transcripcion->tomada_por === $usuario->getKey();

        if (! $esSuya && ! ($permitirAdmin && $usuario->es_administrador && $transcripcion->estado === Transcripcion::ESTADO_EN_REVISION)) {
            throw new InvalidArgumentException('Primero toma la fórmula para revisarla.');
        }
    }
}
