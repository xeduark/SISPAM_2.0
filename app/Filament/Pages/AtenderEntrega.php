<?php

namespace App\Filament\Pages;

use App\Contracts\Ticket\Dto\TicketDto;
use App\Contracts\Ticket\TicketConsultaInterface;
use App\Filament\Resources\EntregaResource;
use App\Models\Entrega;
use App\Models\EntregaItem;
use App\Services\Entrega\RegistrarEntrega;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class AtenderEntrega extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Atender entrega';

    protected static ?string $navigationGroup = 'Entrega';

    protected static ?string $title = 'Atender entrega';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.atender-entrega';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->puede('entrega.atender');
    }

    /** @var array<string, mixed> */
    public array $busqueda = [];

    /** @var array<string, mixed> */
    public array $atencion = [];

    /** Vista serializable del ticket (Livewire no serializa el DTO). */
    public ?array $ticket = null;

    public bool $consultado = false;

    public function mount(): void
    {
        $this->busquedaForm->fill([]);
        $this->atencionForm->fill([
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_parentesco' => 'Paciente',
        ]);
    }

    protected function getForms(): array
    {
        return [
            'busquedaForm',
            'atencionForm',
        ];
    }

    public function busquedaForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('ticket_numero')
                    ->label('Número o turno del ticket')
                    ->placeholder('Ej. T-1001')
                    ->required()
                    ->maxLength(64)
                    ->autocomplete(false),
            ])
            ->statePath('busqueda');
    }

    public function atencionForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Radio::make('tipo')
                    ->label('Tipo de entrega')
                    ->options([
                        Entrega::TIPO_PRESENCIAL => 'Presencial',
                        Entrega::TIPO_DOMICILIO => 'Domicilio (Dómina)',
                    ])
                    ->inline()
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('receptor_nombre')
                    ->label('Nombre de quien recibe')
                    ->required(fn (Get $get): bool => $get('tipo') === Entrega::TIPO_PRESENCIAL)
                    ->visible(fn (Get $get): bool => $get('tipo') === Entrega::TIPO_PRESENCIAL)
                    ->maxLength(255),
                Forms\Components\TextInput::make('receptor_documento')
                    ->label('Documento de quien recibe')
                    ->required(fn (Get $get): bool => $get('tipo') === Entrega::TIPO_PRESENCIAL)
                    ->visible(fn (Get $get): bool => $get('tipo') === Entrega::TIPO_PRESENCIAL)
                    ->maxLength(40),
                Forms\Components\TextInput::make('receptor_parentesco')
                    ->label('Parentesco / relación')
                    ->visible(fn (Get $get): bool => $get('tipo') === Entrega::TIPO_PRESENCIAL)
                    ->maxLength(80),
                Forms\Components\FileUpload::make('firma')
                    ->label('Firma del receptor')
                    ->image()
                    ->disk('local')
                    ->directory('soportes/firmas-entrega/tmp')
                    ->visibility('private')
                    ->required(fn (Get $get): bool => $get('tipo') === Entrega::TIPO_PRESENCIAL)
                    ->visible(fn (Get $get): bool => $get('tipo') === Entrega::TIPO_PRESENCIAL)
                    ->helperText('Foto o imagen de la firma de quien recibe.'),
                Forms\Components\Textarea::make('observaciones')
                    ->label('Observaciones')
                    ->rows(2)
                    ->columnSpanFull(),
                Forms\Components\Repeater::make('items')
                    ->label('Medicamentos del ticket')
                    ->schema([
                        Forms\Components\Hidden::make('ticket_item_id'),
                        Forms\Components\Hidden::make('codigo'),
                        Forms\Components\Hidden::make('nombre'),
                        Forms\Components\Hidden::make('cantidad_solicitada'),
                        Forms\Components\Hidden::make('unidad'),
                        Forms\Components\Placeholder::make('resumen')
                            ->label('Medicamento')
                            ->content(fn (Get $get): string => trim(
                                ($get('codigo') ?? '').' — '.($get('nombre') ?? '').' ('
                                .($get('cantidad_solicitada') ?? '').' '.($get('unidad') ?? '').')'
                            )),
                        Forms\Components\Select::make('resultado')
                            ->label('Resultado')
                            ->options([
                                EntregaItem::RESULTADO_ENTREGADO => 'Entregado',
                                EntregaItem::RESULTADO_FALTANTE => 'Faltante',
                                EntregaItem::RESULTADO_PENDIENTE => 'Pendiente',
                            ])
                            ->required()
                            ->live()
                            ->default(EntregaItem::RESULTADO_ENTREGADO),
                        Forms\Components\TextInput::make('cantidad_entregada')
                            ->label('Cantidad entregada')
                            ->numeric()
                            ->required()
                            ->visible(fn (Get $get): bool => $get('resultado') === EntregaItem::RESULTADO_ENTREGADO)
                            ->default(fn (Get $get) => $get('cantidad_solicitada')),
                        Forms\Components\TextInput::make('motivo')
                            ->label('Motivo')
                            ->required(fn (Get $get): bool => in_array($get('resultado'), [
                                EntregaItem::RESULTADO_FALTANTE,
                                EntregaItem::RESULTADO_PENDIENTE,
                            ], true))
                            ->visible(fn (Get $get): bool => in_array($get('resultado'), [
                                EntregaItem::RESULTADO_FALTANTE,
                                EntregaItem::RESULTADO_PENDIENTE,
                            ], true)),
                    ])
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->columnSpanFull(),
            ])
            ->columns(2)
            ->statePath('atencion');
    }

    public function buscar(TicketConsultaInterface $tickets): void
    {
        $this->busquedaForm->validate();
        $numero = trim((string) ($this->busqueda['ticket_numero'] ?? ''));

        $dto = $tickets->buscarPorNumero($numero);
        $this->consultado = true;

        if (! $dto) {
            $this->ticket = null;
            Notification::make()
                ->title('Ticket no encontrado')
                ->body("No hay un ticket con el número «{$numero}».")
                ->danger()
                ->send();

            return;
        }

        if (! $dto->listoParaEntrega()) {
            $this->ticket = null;
            Notification::make()
                ->title('Ticket no listo')
                ->body('El ticket no está en estado listo o parcial para entrega.')
                ->warning()
                ->send();

            return;
        }

        $this->ticket = $this->ticketAArray($dto);
        $this->emitirAvisos($dto);

        $this->atencionForm->fill([
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_nombre' => $dto->paciente->nombreCompleto,
            'receptor_documento' => $dto->paciente->numeroDocumento,
            'receptor_parentesco' => 'Paciente',
            'observaciones' => null,
            'firma' => null,
            'items' => collect($dto->items)->map(fn ($item): array => [
                'ticket_item_id' => $item->id,
                'codigo' => $item->codigo,
                'nombre' => $item->nombre,
                'cantidad_solicitada' => $item->cantidad,
                'unidad' => $item->unidad,
                'resultado' => EntregaItem::RESULTADO_ENTREGADO,
                'cantidad_entregada' => $item->cantidad,
                'motivo' => null,
            ])->all(),
        ]);
    }

    public function limpiar(): void
    {
        $this->ticket = null;
        $this->consultado = false;
        $this->busquedaForm->fill([]);
        $this->atencionForm->fill([
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_parentesco' => 'Paciente',
            'items' => [],
        ]);
    }

    public function registrar(TicketConsultaInterface $tickets, RegistrarEntrega $registrar): void
    {
        if (! $this->ticket) {
            return;
        }

        $dto = $tickets->buscarPorNumero((string) $this->ticket['numero']);
        if (! $dto || ! $dto->listoParaEntrega()) {
            Notification::make()
                ->title('Ticket no disponible')
                ->body('Vuelve a consultar el ticket antes de registrar.')
                ->danger()
                ->send();

            return;
        }

        $this->atencionForm->validate();
        $datos = $this->atencionForm->getState();

        $items = collect($datos['items'] ?? [])->map(function (array $item): array {
            $resultado = $item['resultado'];
            $cantidad = $resultado === EntregaItem::RESULTADO_ENTREGADO
                ? (float) ($item['cantidad_entregada'] ?? 0)
                : 0;

            return [
                'ticket_item_id' => (string) $item['ticket_item_id'],
                'codigo' => (string) $item['codigo'],
                'nombre' => (string) $item['nombre'],
                'cantidad_solicitada' => (float) $item['cantidad_solicitada'],
                'unidad' => (string) ($item['unidad'] ?? 'UND'),
                'cantidad_entregada' => $cantidad,
                'resultado' => $resultado,
                'motivo' => $item['motivo'] ?? null,
            ];
        })->all();

        $firmaContenido = null;
        if (($datos['tipo'] ?? null) === Entrega::TIPO_PRESENCIAL) {
            $firmaContenido = $this->contenidoFirma($datos['firma'] ?? null);
            if (blank($firmaContenido)) {
                Notification::make()
                    ->title('Falta la firma')
                    ->body('La entrega presencial requiere la firma del receptor.')
                    ->danger()
                    ->send();

                return;
            }
        }

        try {
            $entrega = $registrar->handle($dto, auth()->user(), [
                'tipo' => $datos['tipo'],
                'observaciones' => $datos['observaciones'] ?? null,
                'receptor_nombre' => $datos['receptor_nombre'] ?? null,
                'receptor_documento' => $datos['receptor_documento'] ?? null,
                'receptor_parentesco' => $datos['receptor_parentesco'] ?? null,
                'firma_contenido' => $firmaContenido,
                'items' => $items,
            ]);
        } catch (\InvalidArgumentException $e) {
            Notification::make()
                ->title('No se pudo registrar')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Entrega registrada')
            ->body('Ticket '.$entrega->ticket_numero.' — estado: '.$entrega->estado)
            ->success()
            ->send();

        $this->limpiar();
        $this->redirect(EntregaResource::getUrl('view', ['record' => $entrega]));
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketAArray(TicketDto $ticket): array
    {
        return [
            'numero' => $ticket->numero,
            'turno' => $ticket->turno,
            'alto_costo' => $ticket->altoCosto,
            'paciente' => [
                'tipo_documento' => $ticket->paciente->tipoDocumento,
                'numero_documento' => $ticket->paciente->numeroDocumento,
                'nombre_completo' => $ticket->paciente->nombreCompleto,
                'telefono_movil' => $ticket->paciente->telefonoMovil,
                'direccion' => $ticket->paciente->direccion,
                'barrio' => $ticket->paciente->barrio,
                'ciudad' => $ticket->paciente->ciudad,
                'indicaciones_entrega' => $ticket->paciente->indicacionesEntrega,
            ],
        ];
    }

    private function emitirAvisos(TicketDto $ticket): void
    {
        if ($ticket->altoCosto) {
            Notification::make()
                ->title('Alto costo / oncológico')
                ->body('El ticket está marcado como alto costo. Verifica el protocolo antes de dispensar.')
                ->warning()
                ->persistent()
                ->send();
        }

        if (! $ticket->paciente->contactoConfirmado || blank($ticket->paciente->direccion)) {
            Notification::make()
                ->title('Contacto incompleto')
                ->body('El paciente no tiene dirección o contacto confirmado. El domicilio puede fallar.')
                ->warning()
                ->send();
        }

        $pendientesPrevios = EntregaItem::query()
            ->whereHas('entrega', fn ($q) => $q->where('ticket_numero', $ticket->numero))
            ->whereIn('resultado', [EntregaItem::RESULTADO_FALTANTE, EntregaItem::RESULTADO_PENDIENTE])
            ->count();

        if ($pendientesPrevios > 0) {
            Notification::make()
                ->title('Hay ítems pendientes o faltantes')
                ->body("Este ticket tiene {$pendientesPrevios} medicamento(s) pendientes/faltantes de entregas anteriores.")
                ->info()
                ->send();
        }
    }

    private function contenidoFirma(mixed $firma): ?string
    {
        if ($firma instanceof TemporaryUploadedFile) {
            return $firma->get();
        }

        if (is_array($firma)) {
            $firma = reset($firma) ?: null;
        }

        if (is_string($firma) && $firma !== '' && Storage::disk('local')->exists($firma)) {
            return Storage::disk('local')->get($firma);
        }

        return is_string($firma) && $firma !== '' ? $firma : null;
    }
}
