<?php

namespace App\Filament\Pages;

use App\Contracts\Ticket\Dto\TicketDto;
use App\Contracts\Ticket\TicketConsultaInterface;
use App\Filament\Resources\EntregaResource;
use App\Models\Entrega;
use App\Models\EntregaItem;
use App\Models\Sede;
use App\Models\Ticket;
use App\Services\Entrega\RegistrarEntrega;
use App\Services\Entrega\SaldoTicket;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
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

    public ?array $ticket = null;

    public bool $consultado = false;

    public bool $ticketBloqueado = false;

    public ?string $mensajeBloqueo = null;

    public bool $procesando = false;

    public function mount(): void
    {
        $this->busquedaForm->fill([]);
        $this->atencionForm->fill([
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_parentesco' => 'Paciente',
            'sede_id' => null,
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
                    ->label('Número de turno')
                    ->placeholder('Ej. T-1001')
                    ->required()
                    ->maxLength(64)
                    ->autocomplete(false)
                    ->helperText('Consulta el número de turno para visualizar los medicamentos pendientes de dispensación.'),
            ])
            ->statePath('busqueda');
    }

    public function atencionForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Sede y tipo de entrega')
                    ->schema([
                        Forms\Components\Hidden::make('sede_id'),
                        Forms\Components\Placeholder::make('sede_ticket')
                            ->label('Sede de atención')
                            ->content(function (Get $get): string {
                                $sedeId = (int) ($get('sede_id') ?? 0);
                                if ($sedeId <= 0) {
                                    return 'Se muestra al consultar el ticket.';
                                }

                                $nombre = Sede::query()->whereKey($sedeId)->value('nombre');

                                return $nombre
                                    ? "{$nombre} — los medicamentos se reclaman aquí (no se puede cambiar)"
                                    : "Sede #{$sedeId} — sede del ticket (no se puede cambiar)";
                            })
                            ->helperText('El ticket queda amarrado a la sede donde se generó. La entrega se registra siempre en esa misma sede.'),
                        Forms\Components\Radio::make('tipo')
                            ->label('¿Cómo se entrega?')
                            ->options([
                                Entrega::TIPO_PRESENCIAL => 'Presencial (paciente o autorizado en el mostrador)',
                                Entrega::TIPO_DOMICILIO => 'Domicilio (envío a la dirección del paciente)',
                            ])
                            ->required()
                            ->live(),
                    ]),
                Forms\Components\Section::make('Medicamentos a dispensar')
                    ->description('Indica cuánto entregas ahora. Lo no entregado queda como cantidad pendiente; el motivo explica el faltante o el aplazamiento.')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->label('')
                            ->schema([
                                Forms\Components\Hidden::make('ticket_item_id'),
                                Forms\Components\Hidden::make('codigo'),
                                Forms\Components\Hidden::make('nombre'),
                                Forms\Components\Hidden::make('cantidad_solicitada'),
                                Forms\Components\Hidden::make('unidad'),
                                Forms\Components\Placeholder::make('resumen')
                                    ->label('Medicamento')
                                    ->content(fn (Get $get): string => trim(
                                        ($get('codigo') ?? '').' — '.($get('nombre') ?? '')
                                    )),
                                Forms\Components\Placeholder::make('solicitado')
                                    ->label('Pendiente por entregar')
                                    ->content(fn (Get $get): string => trim(
                                        ($get('cantidad_solicitada') ?? '0').' '.($get('unidad') ?? '')
                                    )),
                                Forms\Components\TextInput::make('cantidad_entregada')
                                    ->label('Cantidad a entregar ahora')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->live(debounce: 400)
                                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                        $solicitada = (float) ($get('cantidad_solicitada') ?? 0);
                                        $entregada = max(0, min($solicitada, (float) $state));
                                        $set('cantidad_entregada', $entregada);
                                        $pendiente = round($solicitada - $entregada, 2);
                                        if ($pendiente <= 0) {
                                            $set('resultado', EntregaItem::RESULTADO_ENTREGADO);
                                            $set('motivo', null);
                                        } elseif ($entregada > 0) {
                                            $set('resultado', EntregaItem::RESULTADO_PARCIAL);
                                        } elseif (! in_array($get('resultado'), [
                                            EntregaItem::RESULTADO_FALTANTE,
                                            EntregaItem::RESULTADO_PENDIENTE,
                                        ], true)) {
                                            $set('resultado', EntregaItem::RESULTADO_FALTANTE);
                                        }
                                    }),
                                Forms\Components\Placeholder::make('pendiente_calc')
                                    ->label('Quedará pendiente')
                                    ->content(function (Get $get): string {
                                        $solicitada = (float) ($get('cantidad_solicitada') ?? 0);
                                        $entregada = (float) ($get('cantidad_entregada') ?? 0);

                                        return max(0, round($solicitada - $entregada, 2)).' '.($get('unidad') ?? '');
                                    }),
                                Forms\Components\Select::make('resultado')
                                    ->label('Clasificación del pendiente / faltante')
                                    ->helperText('Faltante = sin existencias. Pendiente = se aplaza la entrega. La cantidad pendiente siempre se calcula.')
                                    ->options(function (Get $get): array {
                                        $solicitada = (float) ($get('cantidad_solicitada') ?? 0);
                                        $entregada = (float) ($get('cantidad_entregada') ?? 0);
                                        if ($entregada <= 0) {
                                            return [
                                                EntregaItem::RESULTADO_FALTANTE => 'Faltante (sin existencias)',
                                                EntregaItem::RESULTADO_PENDIENTE => 'Aplazado (se entrega después)',
                                            ];
                                        }
                                        if ($entregada < $solicitada) {
                                            return [
                                                EntregaItem::RESULTADO_PARCIAL => 'Entrega parcial',
                                            ];
                                        }

                                        return [
                                            EntregaItem::RESULTADO_ENTREGADO => 'Entregado completo',
                                        ];
                                    })
                                    ->required()
                                    ->live(),
                                Forms\Components\TextInput::make('motivo')
                                    ->label('Motivo')
                                    ->required(function (Get $get): bool {
                                        $solicitada = (float) ($get('cantidad_solicitada') ?? 0);
                                        $entregada = (float) ($get('cantidad_entregada') ?? 0);

                                        return $entregada < $solicitada;
                                    })
                                    ->visible(function (Get $get): bool {
                                        $solicitada = (float) ($get('cantidad_solicitada') ?? 0);
                                        $entregada = (float) ($get('cantidad_entregada') ?? 0);

                                        return $entregada < $solicitada;
                                    })
                                    ->placeholder('Ej. Faltante por disponibilidad'),
                            ])
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(2)
                            ->columnSpanFull()
                            ->live(),
                        Forms\Components\Placeholder::make('resumen_cantidades')
                            ->label('Resumen antes de confirmar')
                            ->content(function (Get $get): string {
                                $items = $get('items') ?? [];
                                $solicitada = 0.0;
                                $entregada = 0.0;
                                foreach ($items as $item) {
                                    $solicitada += (float) ($item['cantidad_solicitada'] ?? 0);
                                    $entregada += (float) ($item['cantidad_entregada'] ?? 0);
                                }
                                $pendiente = max(0, round($solicitada - $entregada, 2));

                                return "Se entregarán {$entregada} de {$solicitada} unidades. Quedarán {$pendiente} pendientes.";
                            })
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Receptor y firma')
                    ->description('Obligatorio solo en entrega presencial.')
                    ->visible(fn (Get $get): bool => $get('tipo') === Entrega::TIPO_PRESENCIAL)
                    ->schema([
                        Forms\Components\TextInput::make('receptor_nombre')
                            ->label('Nombre de quien recibe')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('receptor_documento')
                            ->label('Documento de quien recibe')
                            ->required()
                            ->maxLength(40),
                        Forms\Components\TextInput::make('receptor_parentesco')
                            ->label('Parentesco / relación')
                            ->maxLength(80),
                        Forms\Components\FileUpload::make('firma')
                            ->label('Firma o evidencia de recibido')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                            ->maxSize(5120)
                            ->disk('local')
                            ->directory('soportes/firmas-entrega/tmp')
                            ->visibility('private')
                            ->required()
                            ->helperText('Imagen JPG, PNG o WEBP de máximo 5 MB.'),
                    ])
                    ->columns(2),
                Forms\Components\Textarea::make('observaciones')
                    ->label('Observaciones generales')
                    ->rows(2)
                    ->columnSpanFull(),
            ])
            ->statePath('atencion');
    }

    public function buscar(TicketConsultaInterface $tickets, SaldoTicket $saldo): void
    {
        $this->busquedaForm->validate();
        $numero = trim((string) ($this->busqueda['ticket_numero'] ?? ''));

        $dto = $tickets->buscarPorNumero($numero);
        $this->consultado = true;
        $this->ticketBloqueado = false;
        $this->mensajeBloqueo = null;
        $this->procesando = false;

        if (! $dto) {
            $this->ticket = null;
            Notification::make()
                ->title('Ticket no encontrado')
                ->body("No hay un ticket con el número «{$numero}».")
                ->danger()
                ->send();

            return;
        }

        // La sede de dispensación es la del ticket (no la del perfil del
        // usuario: el dispensador puede rotar de sede). Se valida al registrar.

        if (! $dto->listoParaEntrega()) {
            $this->ticket = null;

            [$titulo, $cuerpo] = match ($dto->estado) {
                Ticket::ESTADO_ENTREGADO => [
                    'Ticket ya dispensado',
                    'Este ticket ya fue dispensado completamente.',
                ],
                Ticket::ESTADO_ANULADO => [
                    'Ticket anulado',
                    'Este ticket se encuentra anulado y no puede ser dispensado.',
                ],
                Ticket::ESTADO_VENCIDO => [
                    'Ticket vencido',
                    'Este ticket se encuentra vencido y no puede ser dispensado.',
                ],
                default => [
                    'Ticket no disponible',
                    'Este ticket todavía no está disponible para dispensación.',
                ],
            };

            Notification::make()
                ->title($titulo)
                ->body($cuerpo)
                ->warning()
                ->send();

            return;
        }

        if ($saldo->ticketCompletamenteDispensado($dto)) {
            $this->ticket = $this->ticketAArray($dto);
            $this->ticketBloqueado = true;
            $this->mensajeBloqueo = 'Este ticket ya fue dispensado completamente.';
            Notification::make()
                ->title('Ticket ya dispensado')
                ->body($this->mensajeBloqueo)
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        $pendientes = $saldo->lineasPendientes($dto);
        if ($pendientes === []) {
            $this->ticket = $this->ticketAArray($dto);
            $this->ticketBloqueado = true;
            $this->mensajeBloqueo = 'Este ticket ya fue dispensado completamente.';

            return;
        }

        $this->ticket = $this->ticketAArray($dto);
        $this->emitirAvisos($dto);

        $this->atencionForm->fill([
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'sede_id' => $dto->sedeId,
            'receptor_nombre' => $dto->paciente->nombreCompleto,
            'receptor_documento' => $dto->paciente->numeroDocumento,
            'receptor_parentesco' => 'Paciente',
            'observaciones' => null,
            'firma' => null,
            'items' => collect($pendientes)->map(fn (array $linea): array => [
                'ticket_item_id' => $linea['item']->id,
                'codigo' => $linea['item']->codigo,
                'nombre' => $linea['item']->nombre,
                'cantidad_solicitada' => $linea['pendiente'],
                'unidad' => $linea['item']->unidad,
                'resultado' => EntregaItem::RESULTADO_ENTREGADO,
                'cantidad_entregada' => $linea['pendiente'],
                'motivo' => null,
            ])->all(),
        ]);
    }

    public function limpiar(): void
    {
        $this->ticket = null;
        $this->consultado = false;
        $this->ticketBloqueado = false;
        $this->mensajeBloqueo = null;
        $this->procesando = false;
        $this->busquedaForm->fill([]);
        $this->atencionForm->fill([
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'sede_id' => null,
            'receptor_parentesco' => 'Paciente',
            'items' => [],
        ]);
    }

    public function registrar(TicketConsultaInterface $tickets, RegistrarEntrega $registrar): void
    {
        if (! $this->ticket || $this->ticketBloqueado) {
            return;
        }

        if ($this->procesando) {
            return;
        }

        $this->procesando = true;

        try {
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

            $items = collect($datos['items'] ?? [])->map(fn (array $item): array => [
                'ticket_item_id' => (string) $item['ticket_item_id'],
                'codigo' => (string) $item['codigo'],
                'nombre' => (string) $item['nombre'],
                'cantidad_solicitada' => (float) $item['cantidad_solicitada'],
                'unidad' => (string) ($item['unidad'] ?? 'UND'),
                'cantidad_entregada' => (float) ($item['cantidad_entregada'] ?? 0),
                'resultado' => (string) ($item['resultado'] ?? EntregaItem::RESULTADO_FALTANTE),
                'motivo' => $item['motivo'] ?? null,
            ])->all();

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

            $entrega = $registrar->handle($dto, auth()->user(), [
                'tipo' => $datos['tipo'],
                // Siempre la sede del ticket; el selector ya no es editable.
                'sede_id' => $dto->sedeId,
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
        } finally {
            $this->procesando = false;
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
            'sede_id' => $ticket->sedeId,
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
                ->body('Verifica el protocolo antes de dispensar.')
                ->warning()
                ->send();
        }

        if (! $ticket->paciente->contactoConfirmado || blank($ticket->paciente->direccion)) {
            Notification::make()
                ->title('Contacto incompleto')
                ->body('Revisa dirección y teléfono antes de un domicilio.')
                ->warning()
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
