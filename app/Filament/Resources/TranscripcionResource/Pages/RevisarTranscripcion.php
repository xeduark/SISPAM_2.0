<?php

namespace App\Filament\Resources\TranscripcionResource\Pages;

use App\Contracts\Inventario\CatalogoInventarioInterface;
use App\Contracts\Inventario\Coincidencia;
use App\Filament\Resources\TranscripcionResource;
use App\Jobs\LeerFormula;
use App\Models\Transcripcion;
use App\Services\Transcripcion\OrdenDeEntrega;
use App\Services\Transcripcion\RevisarTranscripcion as Reglas;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Arriba, lado a lado: la fórmula original y la previsualización del acta que se
 * generará (qué va a ventanilla, qué a domicilio, qué no se dispensa). Abajo, a todo
 * lo ancho, el formulario de fórmulas y medicamentos. La previsualización se arma
 * con lo que hay en pantalla, sin guardar.
 * Las reglas viven en App\Services\Transcripcion\RevisarTranscripcion.
 */
class RevisarTranscripcion extends Page implements HasForms
{
    use InteractsWithForms, InteractsWithRecord;

    protected static string $resource = TranscripcionResource::class;

    protected static string $view = 'filament.resources.transcripcion.revisar';

    protected ?string $maxContentWidth = 'full';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var list<string> */
    public array $faltas = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(auth()->user()?->puede('transcripcion.ver'), 403);
        $this->llenar();
    }

    public function getTitle(): string
    {
        return 'Transcribir: '.($this->record->paciente?->nombre_completo ?? 'paciente');
    }

    public function esMia(): bool
    {
        return $this->record->estado === Transcripcion::ESTADO_EN_REVISION
            && $this->record->tomada_por === auth()->id();
    }

    private function llenar(): void
    {
        $this->record->refresh();
        $this->form->fill([
            'formulas' => $this->record->formulas() ?: [['numero' => 1]],
            'items' => $this->record->items()->orderBy('formula')->orderBy('id')->get()->map(fn ($i) => $i->only([
                'id', 'formula', 'texto_prescrito', 'concentracion', 'posologia', 'codigo_inventario', 'nombre_inventario',
                'similitud', 'alertas', 'cantidad_total', 'duracion_dias', 'meses', 'entrega_mes', 'cantidad_mes', 'revisado',
            ]))->all(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->disabled(fn (): bool => ! $this->esMia())
            ->schema([
                Forms\Components\Repeater::make('formulas')
                    ->label('Prescripción y auditoría (una tarjeta por fórmula de la imagen)')
                    ->itemLabel(fn (array $state): string => 'Fórmula #'.($state['numero'] ?? '?').(filled($state['ips'] ?? null) ? ' · '.$state['ips'] : '')
                        .(! empty($state['rechazada']) ? ' — NO SE DISPENSA' : ''))
                    ->addActionLabel('Agregar otra fórmula de la imagen')
                    ->reorderable(false)
                    ->collapsible()
                    ->columns(6)
                    ->schema([
                        Forms\Components\TextInput::make('numero')->label('#')->numeric()->minValue(1)->required()->distinct()->columnSpan(1),
                        Forms\Components\TextInput::make('ips')->label('IPS que expide')->live(onBlur: true)->columnSpan(3),
                        Forms\Components\DatePicker::make('fecha_expedicion')->label('Expedición')->native(false)->displayFormat('d/m/Y')->columnSpan(1),
                        Forms\Components\DatePicker::make('vigencia')->label('Vigencia')->native(false)->displayFormat('d/m/Y')->columnSpan(1),
                        Forms\Components\TextInput::make('cie10_principal.codigo')->label('CIE-10')->maxLength(6)->columnSpan(1),
                        Forms\Components\TextInput::make('cie10_principal.descripcion')->label('Diagnóstico')->columnSpan(3),
                        Forms\Components\TextInput::make('mipres')->label('MIPRES')->columnSpan(1),
                        Forms\Components\TextInput::make('autorizacion')->label('Autorización / NUA')->columnSpan(1),
                        Forms\Components\TextInput::make('medico.nombre')->label('Médico tratante')->live(onBlur: true)->columnSpan(3),
                        Forms\Components\TextInput::make('medico.registro_medico')->label('Registro médico / cédula')->columnSpan(1),
                        Forms\Components\TextInput::make('medico.especialidad')->label('Especialidad')->columnSpan(2),
                        // Una fórmula de la imagen que no se dispensa (vencida, sin médico…). Las demás siguen.
                        // El motivo sale en el acta de entrega que firma el paciente.
                        Forms\Components\Toggle::make('rechazada')->label('Esta fórmula NO se dispensa')->live()->inline(false)->columnSpan(2),
                        Forms\Components\Select::make('motivo_rechazo')->label('Motivo (lo verá el paciente en el acta)')
                            ->options(Transcripcion::MOTIVOS_RECHAZO_FORMULA)
                            ->required(fn (Get $get): bool => (bool) $get('rechazada'))
                            ->visible(fn (Get $get): bool => (bool) $get('rechazada'))->live()->columnSpan(2),
                        Forms\Components\TextInput::make('detalle_rechazo')->label('Detalle')->maxLength(200)
                            ->required(fn (Get $get): bool => $get('motivo_rechazo') === 'otro')
                            ->visible(fn (Get $get): bool => (bool) $get('rechazada'))->columnSpan(2),
                    ]),

                Forms\Components\Repeater::make('items')
                    ->label('Medicamentos')
                    ->itemLabel(fn (array $state): string => (! empty($state['revisado']) ? '✓ ' : '○ ').'Fórmula #'.($state['formula'] ?? '?').' · '.str($state['texto_prescrito'] ?? 'Nuevo medicamento')->limit(70))
                    ->addActionLabel('Agregar medicamento')
                    ->reorderable(false)
                    ->collapsible()
                    ->columns(6)
                    ->schema([
                        Forms\Components\Hidden::make('id'),
                        Forms\Components\Hidden::make('nombre_inventario'),
                        Forms\Components\Select::make('formula')
                            ->label('Fórmula')
                            ->options(fn (Get $get): array => collect($get('../../formulas'))->pluck('numero')->filter()
                                ->mapWithKeys(fn ($n) => [$n => "#{$n}"])->all())
                            ->default(1)
                            ->required()
                            ->live()
                            ->columnSpan(1),
                        Forms\Components\TextInput::make('texto_prescrito')->label('Como dice la fórmula')->required()->maxLength(250)->live(onBlur: true)->columnSpan(4),
                        // Se marca después de comparar la línea con la fórmula original. Sin todas marcadas no se confirma.
                        Forms\Components\Toggle::make('revisado')->label('Revisado')->inline(false)->live()->onColor('success')->columnSpan(1),
                        Forms\Components\Select::make('codigo_inventario')
                            ->label('Producto de bodega')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->buscarEnInventario($search))
                            ->getOptionLabelUsing(fn ($value, Get $get): string => $get('nombre_inventario') ? "{$value} · {$get('nombre_inventario')}" : (string) $value)
                            ->live()
                            ->helperText(fn (Get $get): ?string => $this->textoAlertas($get('alertas'), $get('similitud')))
                            ->columnSpan(6),
                        Forms\Components\TextInput::make('posologia')->label('Posología')->maxLength(250)->columnSpan(3),
                        Forms\Components\TextInput::make('duracion_dias')->label('Días tratamiento')->numeric()->minValue(1)->columnSpan(1),
                        Forms\Components\TextInput::make('cantidad_total')->label('Total prescrito')->numeric()->minValue(0)->live(onBlur: true)->columnSpan(1),
                        Forms\Components\TextInput::make('meses')->label('Meses')->numeric()->minValue(1)->live(onBlur: true)->columnSpan(1),
                        Forms\Components\TextInput::make('entrega_mes')->label('Entrega mes n°')->numeric()->minValue(1)->live(onBlur: true)->columnSpan(2),
                        Forms\Components\TextInput::make('cantidad_mes')->label('Entregar hoy')->numeric()->minValue(0)->live(onBlur: true)->columnSpan(2),
                        Forms\Components\Placeholder::make('saldo')
                            ->label('Próximo saldo')
                            ->content(fn (Get $get): string => $this->saldo($get))
                            ->columnSpan(2),
                    ]),
            ]);
    }

    /** El botón ✓ de la previsualización: marca o desmarca una línea sin bajar al formulario. */
    public function alternarRevisado(string $clave): void
    {
        if (! $this->esMia() || ! isset($this->data['items'][$clave])) {
            return;
        }
        $this->data['items'][$clave]['revisado'] = empty($this->data['items'][$clave]['revisado']);
    }

    /**
     * El acta que saldría con lo que hay en pantalla (sin guardar): cada fórmula con sus
     * medicamentos y a dónde va cada uno según el stock de la sede.
     *
     * @return array{formulas: list<array<string, mixed>>, huerfanas: list<array<string, mixed>>, ventanilla: int, parcial: int, domicilio: int, noDispensa: int, revisadas: int, porRevisar: int, stockConsultado: bool}
     */
    public function previsualizacion(): array
    {
        $formulas = collect($this->data['formulas'] ?? [])->values();
        $rechazadas = $formulas->filter(fn ($f) => ! empty($f['rechazada']))->pluck('numero')->map(fn ($n) => (int) $n)->all();

        $lineas = collect($this->data['items'] ?? [])->map(fn (array $i, $clave) => [
            'clave' => (string) $clave,
            'formula' => (int) ($i['formula'] ?? 0),
            'codigo' => filled($i['codigo_inventario'] ?? null) ? (string) $i['codigo_inventario'] : null,
            'producto' => ($i['nombre_inventario'] ?? null) ?: ($i['texto_prescrito'] ?? 'Sin nombre'),
            'prescrito' => $i['texto_prescrito'] ?? '',
            'cantidad' => (int) ($i['cantidad_mes'] ?? 0),
            'revisado' => ! empty($i['revisado']),
        ])->values();

        // Solo lo que se va a dispensar, con producto y cantidad, pasa por el stock.
        $dispensables = $lineas->filter(fn ($l) => ! in_array($l['formula'], $rechazadas, true) && $l['codigo'] && $l['cantidad'] > 0)->values();
        [$ventanilla, $pendientes, $consultado] = $this->separar($dispensables);
        $entrega = $ventanilla->pluck('entrega', 'clave');
        $falta = $pendientes->pluck('falta', 'clave');

        $destino = function (array $l) use ($rechazadas, $consultado, $entrega, $falta): array {
            return match (true) {
                in_array($l['formula'], $rechazadas, true) => ['no_dispensa', 'No se dispensa'],
                ! $l['codigo'] => ['sin_producto', 'Falta el producto'],
                $l['cantidad'] <= 0 => ['sin_cantidad', 'Falta la cantidad'],
                ! $consultado => ['ventanilla', 'Ventanilla (sin consultar stock)'],
                $falta->has($l['clave']) && $entrega->has($l['clave']) => ['parcial', "Parcial: {$entrega[$l['clave']]} ventanilla + {$falta[$l['clave']]} domicilio"],
                $falta->has($l['clave']) => ['domicilio', 'Domicilio'],
                default => ['ventanilla', 'Ventanilla'],
            };
        };

        $lineas = $lineas->map(fn ($l) => $l + array_combine(['destino', 'etiqueta'], $destino($l)));

        return [
            'formulas' => $formulas->map(fn ($f) => $f + [
                'lineas' => $lineas->where('formula', (int) ($f['numero'] ?? 0))->values()->all(),
            ])->all(),
            'huerfanas' => $lineas->reject(fn ($l) => $formulas->pluck('numero')->map(fn ($n) => (int) $n)->contains($l['formula']))->values()->all(),
            'ventanilla' => $lineas->where('destino', 'ventanilla')->count(),
            'parcial' => $lineas->where('destino', 'parcial')->count(),
            'domicilio' => $lineas->where('destino', 'domicilio')->count(),
            'noDispensa' => $lineas->where('destino', 'no_dispensa')->count(),
            'revisadas' => $lineas->where('destino', '!=', 'no_dispensa')->where('revisado', true)->count(),
            'porRevisar' => $lineas->where('destino', '!=', 'no_dispensa')->where('revisado', false)->count(),
            'stockConsultado' => $consultado,
        ];
    }

    /** @return array{0: Collection, 1: Collection, 2: bool} */
    private function separar(Collection $lineas): array
    {
        $sede = (string) ($this->record->sede?->codigo ?? $this->record->ticket?->sede?->codigo ?? '');

        try {
            return app(OrdenDeEntrega::class)->separarPorStock($lineas, $sede, cacheado: true);
        } catch (Throwable) {
            return [$lineas, collect(), false];
        }
    }

    /** @return array<string, string> */
    private function buscarEnInventario(string $texto): array
    {
        if (mb_strlen(trim($texto)) < 3) {
            return [];
        }

        try {
            return collect(app(CatalogoInventarioInterface::class)->buscar($texto, 15))
                ->mapWithKeys(fn (Coincidencia $c) => [$c->codigo => "{$c->codigo} · {$c->nombre}".($c->stock !== null ? " · stock {$c->stock}" : '')])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function textoAlertas(mixed $alertas, mixed $similitud): ?string
    {
        $partes = array_merge($similitud !== null ? ["Similitud {$similitud}%"] : [], (array) $alertas);

        return $partes === [] ? null : '⚠ '.implode(' · ', $partes);
    }

    private function saldo(Get $get): string
    {
        $total = (float) $get('cantidad_total');
        $mes = (float) $get('cantidad_mes');
        $n = max(1, (int) $get('entrega_mes'));

        if ($total <= 0 || $mes <= 0) {
            return '—';
        }
        $saldo = $total - $mes * $n;

        return $saldo < 0 ? "Supera el total en ".abs($saldo) : "{$saldo} por entregar después";
    }

    private function intentar(callable $accion, string $exito): bool
    {
        try {
            $accion(app(Reglas::class));
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('No se pudo')->body($e->getMessage())->send();

            return false;
        }

        $this->faltas = [];
        $this->llenar();
        Notification::make()->success()->title($exito)->send();

        return true;
    }

    private function guardarFormulario(Reglas $reglas): void
    {
        $datos = $this->form->getState();
        $reglas->guardar($this->record, auth()->user(), $datos['formulas'] ?? [], $datos['items'] ?? []);
    }

    protected function getHeaderActions(): array
    {
        $puede = fn (): bool => (bool) auth()->user()?->puede('transcripcion.transcribir');

        $ticketId = $this->record->ticket_id ?? $this->record->soporte?->ticket_id;

        return [
            Action::make('orden')
                ->label('Orden de entrega')
                ->icon('heroicon-m-printer')
                ->color('gray')
                ->visible(fn (): bool => $ticketId !== null && $this->record->estado === Transcripcion::ESTADO_CONFIRMADA)
                ->url(fn (): string => route('tickets.orden-entrega', $ticketId), shouldOpenInNewTab: true),

            // Fase 4: corregir una ya confirmada mientras no se haya entregado. La orden sale como nueva versión.
            Action::make('rectificar')
                ->label('Rectificar')
                ->icon('heroicon-m-pencil-square')
                ->color('warning')
                ->visible(fn (): bool => $this->record->estado === Transcripcion::ESTADO_CONFIRMADA
                    && (bool) auth()->user()?->puede('transcripcion.rectificar'))
                ->modalHeading('Rectificar la transcripción')
                ->modalDescription('Se reabre para corregir. Solo si no se ha entregado nada del ticket. Al confirmar, la orden sale como nueva versión y el cambio queda en la auditoría. Si el ticket ya fue alistado, avisa a farmacia para alistarlo de nuevo.')
                ->form([
                    Forms\Components\Textarea::make('motivo')->label('¿Qué se corrige y por qué?')->required()->rows(2)->maxLength(250)
                        ->placeholder('Cantidad de Losartán mal digitada: eran 60, no 30'),
                ])
                ->action(fn (array $data) => $this->intentar(fn (Reglas $r) => $r->rectificar($this->record, auth()->user(), $data['motivo']), 'Reabierta para rectificar')),

            Action::make('tomar')
                ->label('Tomar para revisar')
                ->icon('heroicon-m-hand-raised')
                ->visible(fn (): bool => $puede() && ! $this->esMia()
                    && in_array($this->record->estado, [Transcripcion::ESTADO_LEIDA, Transcripcion::ESTADO_EN_REVISION], true))
                ->action(fn () => $this->intentar(fn (Reglas $r) => $r->tomar($this->record, auth()->user()), 'Es tuya: nadie más la puede editar')),

            Action::make('guardar')
                ->label('Guardar')
                ->icon('heroicon-m-check')
                ->color('gray')
                ->visible(fn (): bool => $this->esMia())
                ->action(fn () => $this->intentar(fn (Reglas $r) => $this->guardarFormulario($r), 'Guardado')),

            Action::make('reprocesar')
                ->label('Leer de nuevo con IA')
                ->icon('heroicon-m-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->esMia())
                ->requiresConfirmation()
                ->modalDescription('Se borra lo corregido y la fórmula se vuelve a leer. Queda en cola hasta que termine.')
                ->action(function (): void {
                    $this->record->items()->delete();
                    $this->record->update(['estado' => Transcripcion::ESTADO_EN_COLA, 'tomada_por' => null, 'tomada_en' => null, 'error' => null]);
                    LeerFormula::dispatch($this->record);
                    $this->llenar();
                    Notification::make()->title('La fórmula volvió a la cola de lectura')->send();
                }),

            Action::make('liberar')
                ->label('Soltar')
                ->icon('heroicon-m-lock-open')
                ->color('gray')
                ->visible(fn (): bool => ! $this->record->enRectificacion() && ($this->esMia()
                    || (auth()->user()?->es_administrador && $this->record->estado === Transcripcion::ESTADO_EN_REVISION)))
                ->requiresConfirmation()
                ->modalDescription('Vuelve a la cola sin confirmar. Lo guardado se conserva; lo que no guardaste se pierde.')
                ->action(fn () => $this->intentar(fn (Reglas $r) => $r->liberar($this->record, auth()->user()), 'La fórmula volvió a la cola')),

            Action::make('rechazar')
                ->label('Rechazar')
                ->icon('heroicon-m-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->esMia())
                ->form([
                    Forms\Components\Select::make('motivo')->label('Motivo')->options(Transcripcion::MOTIVOS_RECHAZO)->required(),
                    Forms\Components\Textarea::make('detalle')->label('Detalle')->rows(2)->maxLength(250)
                        ->required(fn (Get $get): bool => $get('motivo') === 'otro'),
                ])
                ->action(fn (array $data) => $this->intentar(
                    fn (Reglas $r) => $r->rechazar($this->record, auth()->user(), $data['motivo'], $data['detalle'] ?? null),
                    'Fórmula rechazada',
                )),

            Action::make('confirmar')
                ->label('Confirmar transcripción')
                ->icon('heroicon-m-check-badge')
                ->color('success')
                ->visible(fn (): bool => $this->esMia())
                ->modalHeading('Confirmar la transcripción')
                ->modalDescription('Revisaste cada línea contra la fórmula original. Se guarda y queda lista para la orden de entrega, como en la previsualización.')
                ->form(fn (): array => $this->record->verificacion_cedula === Transcripcion::CEDULA_NO_ENCONTRADA ? [
                    Forms\Components\Checkbox::make('cedula_revisada')
                        ->label('La cédula no apareció en la lectura. Verifiqué en el original que la fórmula es de este paciente.')
                        ->accepted(),
                ] : [])
                ->action(function (array $data): void {
                    try {
                        $reglas = app(Reglas::class);
                        $this->guardarFormulario($reglas);
                        $this->record->refresh();
                        $faltas = $reglas->faltasParaConfirmar($this->record, (bool) ($data['cedula_revisada'] ?? false));

                        if ($faltas !== []) {
                            $this->faltas = $faltas;
                            $this->llenar();
                            Notification::make()->warning()->title('Falta revisar '.count($faltas).' cosa(s)')->body('Están en la lista arriba del formulario. Lo corregido quedó guardado.')->send();

                            return;
                        }
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('No se pudo')->body($e->getMessage())->send();

                        return;
                    }

                    $this->intentar(fn (Reglas $r) => $r->confirmar($this->record, auth()->user(), (bool) ($data['cedula_revisada'] ?? false)), 'Transcripción confirmada');
                }),
        ];
    }
}
