<?php

namespace App\Filament\Pages;

use App\Filament\Forms\Components\FileUploadSoloGiro;
use App\Filament\Resources\PacienteResource;
use App\Filament\Resources\PacienteResource\Concerns\AvisaSobreSavia;
use App\Filament\Resources\PacienteResource\Concerns\MuestraAvisosDeSavia;
use App\Models\Auditoria;
use App\Models\Paciente;
use App\Models\Ticket;
use App\Services\Orientacion\RegistrarVisita;
use App\Services\Orientacion\VisitaAbierta;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use InvalidArgumentException;

/**
 * La pantalla donde el orientador recibe al paciente.
 *
 * Todo en una sola pantalla y pensada para el celular: se escribe el
 * documento, Savia llena los datos, se confirma el contacto de viva voz, se
 * toma la foto de la fórmula y se genera el ticket.
 *
 * **No duplica nada del registro de Pacientes.** Reutiliza la consulta
 * (`PacienteResource::consultarEnSavia()`), la regla de quién manda sobre cada
 * dato (`Paciente::CAMPOS_CONTACTO`), la sugerencia de prioridad
 * (`Ticket::prioridadSugeridaPara()`) y el armado de la visita
 * (`Services\Orientacion\RegistrarVisita`). Lo único propio es el camino.
 *
 * El asistente de 5 pasos de Pacientes sigue existiendo para corregir la ficha
 * completa; esta pantalla es para atender la fila.
 */
class Orientacion extends Page implements AvisaSobreSavia
{
    use MuestraAvisosDeSavia;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationLabel = 'Orientación';

    protected static ?string $title = 'Orientación';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'filament.pages.orientacion';

    /**
     * Cuántas hojas caben en una visita.
     *
     * Diez alcanza para una fórmula con anexos y respaldos sin que nadie
     * suba el álbum entero por accidente.
     */
    public const MAXIMO_DE_FORMULAS = 10;

    /**
     * El mismo permiso que gobierna el paso Orientación del asistente: quien
     * puede abrir una visita allá puede abrirla aquí.
     */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->puede('orientacion.usar');
    }

    /* ------------------------------------------------------------------ *
     *  Estado de la pantalla
     * ------------------------------------------------------------------ */

    /** @var array<string, mixed> */
    public array $busqueda = [];

    /** @var array<string, mixed> */
    public array $visita = [];

    /**
     * Columnas que llenó Savia, listas para el modelo.
     *
     * @var array<string, mixed>|null
     */
    public ?array $atributos = null;

    /**
     * Contacto que reporta Savia, solo de referencia. Nunca se guarda.
     *
     * @var array<string, string|null>
     */
    public array $contactoSavia = [];

    /**
     * Qué cambiaría al guardar, frente a lo que ya está registrado.
     *
     * @var array<string, string>
     */
    public array $cambios = [];

    public bool $consultado = false;

    public ?int $pacienteExistenteId = null;

    public ?string $registradoDesde = null;

    /** El ticket recién generado: es el tercer momento de la pantalla. */
    public ?int $ticketGeneradoId = null;

    /**
     * La visita del paciente que todavía está viva, si la hay.
     *
     * Se guarda el id y no el objeto porque esto viaja en el estado de
     * Livewire; el ticket se vuelve a cargar cuando hace falta, y
     * `RegistrarVisita` comprueba que de verdad sea del paciente y de la sede.
     */
    public ?int $visitaAbiertaId = null;

    public ?string $visitaAbiertaMotivo = null;

    /**
     * Cuántas hojas del paciente quedaron guardadas sin turno.
     *
     * Pasa cuando la sede no tenía colas el día que se cargaron. Se ofrecen
     * para completarlas sin volver a tomar las fotos.
     */
    public int $hojasSinTurno = 0;

    public function mount(): void
    {
        $this->formularioBusqueda->fill(['tipo_documento' => 'CC']);
        $this->formularioVisita->fill();
    }

    /* ------------------------------------------------------------------ *
     *  Los dos formularios
     * ------------------------------------------------------------------ */

    /**
     * @return array<int, string>
     */
    protected function getForms(): array
    {
        return ['formularioBusqueda', 'formularioVisita'];
    }

    public function formularioBusqueda(Form $form): Form
    {
        return $form
            ->statePath('busqueda')
            ->schema([
                Forms\Components\Select::make('tipo_documento')
                    ->label('Tipo')
                    ->options(config('savia.tipos_documento'))
                    ->default('CC')
                    ->required()
                    ->selectablePlaceholder(false),
                Forms\Components\TextInput::make('numero_documento')
                    ->label('Número de documento')
                    ->placeholder('Escribe la cédula y presiona Enter')
                    // En el celular el teclado abre directamente en números.
                    ->extraInputAttributes(['inputmode' => 'numeric', 'autocomplete' => 'off'])
                    ->autofocus()
                    ->required()
                    ->maxLength(20)
                    // Las mismas reglas que exige el servicio (códigos -1003 y -1004).
                    ->regex('/^[A-Za-z0-9\-]+$/')
                    ->validationMessages([
                        'regex' => 'El documento solo admite letras, números y guiones.',
                    ])
                    ->columnSpan(['default' => 1, 'sm' => 2]),
            ])
            ->columns(['default' => 1, 'sm' => 3]);
    }

    public function formularioVisita(Form $form): Form
    {
        return $form
            ->statePath('visita')
            ->schema([
                Forms\Components\Section::make('Confirmar con el paciente')
                    ->description('El teléfono y la dirección son datos de SISPAM, no de Savia. Confírmalos en cada visita: si el medicamento no está en la sede, hay que enviarlo a domicilio.')
                    ->icon('heroicon-o-phone')
                    ->iconColor('warning')
                    ->schema([
                        Forms\Components\TextInput::make('telefono_movil')
                            ->label('Teléfono móvil')
                            ->tel()
                            ->required()
                            ->maxLength(30)
                            ->helperText(fn (Forms\Get $get): ?string => $this->referenciaSavia($get, 'telefono_movil')),
                        Forms\Components\TextInput::make('telefono')
                            ->label('Teléfono fijo o alterno')
                            ->tel()
                            ->maxLength(30)
                            ->helperText(fn (Forms\Get $get): ?string => $this->referenciaSavia($get, 'telefono')),
                        Forms\Components\TextInput::make('direccion')
                            ->label('Dirección')
                            ->required()
                            ->maxLength(180)
                            ->columnSpanFull()
                            ->helperText(fn (Forms\Get $get): ?string => $this->referenciaSavia($get, 'direccion')),
                        Forms\Components\TextInput::make('barrio')
                            ->label('Barrio')
                            ->maxLength(80)
                            ->helperText(fn (Forms\Get $get): ?string => $this->referenciaSavia($get, 'barrio')),
                        Forms\Components\TextInput::make('ciudad_residencia')
                            ->label('Ciudad o municipio')
                            ->required()
                            ->maxLength(80)
                            ->helperText(fn (Forms\Get $get): ?string => $this->referenciaSavia($get, 'ciudad_residencia')),
                        Forms\Components\Textarea::make('indicaciones_entrega')
                            ->label('Indicaciones de entrega')
                            ->placeholder('Conjunto, torre, apartamento, punto de referencia…')
                            ->helperText('Lo que necesita quien lleva el medicamento para llegar.')
                            ->rows(2)
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\Checkbox::make('contacto_confirmado')
                            ->label('Confirmé teléfono y dirección con el paciente')
                            ->accepted()
                            ->validationMessages([
                                'accepted' => 'Debes confirmar el teléfono y la dirección con el paciente antes de generar el ticket.',
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(['default' => 1, 'sm' => 2]),

                Forms\Components\Section::make('Fórmula médica')
                    ->description('Toma una foto por hoja: anexos, respaldo, lo que traiga. Los medicamentos los captura farmacia al alistar.')
                    ->icon('heroicon-o-camera')
                    ->schema([
                        /*
                         * Una fórmula rara vez cabe en una foto, así que van
                         * varias y el orden importa: es el orden en que se lee.
                         *
                         * Sin `capture`: en el celular el mismo botón ofrece la
                         * cámara o la galería, y en el PC el selector sirve para
                         * los PDF del escáner.
                         *
                         * **HEIC a propósito no está entre los tipos aceptados.**
                         * No es un olvido: es justamente pedir `image/jpeg` lo
                         * que hace que iOS convierta el HEIC al elegir la foto.
                         * Aceptarlo aquí haría que el iPhone entregara el
                         * original, que es lo que no se puede procesar (GD no lo
                         * lee e imagick no está instalado).
                         */
                        FileUploadSoloGiro::make('orden_medica')
                            ->hiddenLabel()
                            ->helperText('Toma las fotos o escoge los archivos. JPG, PNG, WEBP o PDF, hasta 10 archivos de 10 MB cada uno.')
                            ->disk('local')
                            ->directory('soportes/ordenes-medicas')
                            ->visibility('private')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->maxSize(10240)
                            ->multiple()
                            ->minFiles(1)
                            ->maxFiles(self::MAXIMO_DE_FORMULAS)
                            // Agregar más sin perder las que ya se tomaron.
                            ->appendFiles()
                            // Miniaturas en cuadrícula, y el orden se cambia
                            // arrastrando: ese orden es el de las hojas.
                            ->panelLayout('grid')
                            ->reorderable()
                            /*
                             * El lápiz de cada hoja la abre grande, para
                             * revisar que se lea, y ahí mismo se endereza la
                             * que salió acostada. **Solo se gira**: nada de
                             * recortar ni hacer zoom (ver `FileUploadSoloGiro`,
                             * que trae el editor ya puesto).
                             *
                             * Esto hace de «ver en grande» a propósito, en vez
                             * de `openable()`: mientras no se envía el
                             * formulario los archivos son temporales y
                             * `getUploadedFiles()` devuelve null para ellos, así
                             * que ese botón no tendría nada que abrir. Y para
                             * los ya guardados caería a `Storage::url()`, que
                             * en el disco privado sería una URL pública a un
                             * dato de salud. Verlas después de guardar es la
                             * galería, por la ruta protegida.
                             */
                            /*
                             * Se reduce **en el navegador** antes de subir: una
                             * foto de celular de 4 MB sale en unos 400 KB, que
                             * en 4G es la diferencia entre esperar y no esperar.
                             * Al redibujarse en el lienzo se endereza sola según
                             * el EXIF y pierde los metadatos, el GPS incluido.
                             */
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth('2000')
                            ->imageResizeTargetHeight('2000')
                            ->imageResizeUpscale(false)
                            ->required()
                            ->validationMessages([
                                'required' => 'Carga al menos una foto de la fórmula.',
                                'min' => 'Carga al menos una foto de la fórmula.',
                                'max' => 'Son máximo '.self::MAXIMO_DE_FORMULAS.' archivos por visita.',
                                'mimetypes' => 'Solo se aceptan JPG, PNG, WEBP o PDF. Si la foto es de un iPhone y llegó en HEIC, vuelve a tomarla desde la cámara o cambia Ajustes → Cámara → Formatos → Más compatible.',
                            ])
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Prioridad en la fila')
                    ->description('Los preferenciales se llaman de primeras.')
                    ->icon('heroicon-o-user-group')
                    ->schema([
                        Forms\Components\Radio::make('prioridad')
                            ->hiddenLabel()
                            ->options(Ticket::PRIORIDADES)
                            ->inline()
                            ->default(Ticket::PRIORIDAD_NORMAL)
                            ->required()
                            ->live()
                            // Se sugiere por edad y discapacidad según Savia; el
                            // orientador siempre puede cambiarlo.
                            ->helperText(fn (): ?string => $this->motivoSugerido() !== null
                                ? 'Según los datos de Savia, este paciente podría ser preferencial.'
                                : null),
                        Forms\Components\Select::make('motivo_prioridad')
                            ->label('Motivo')
                            ->options(Ticket::MOTIVOS_PRIORIDAD)
                            ->required(fn (Forms\Get $get): bool => $get('prioridad') === Ticket::PRIORIDAD_PREFERENCIAL)
                            ->visible(fn (Forms\Get $get): bool => $get('prioridad') === Ticket::PRIORIDAD_PREFERENCIAL),
                    ])
                    ->columns(['default' => 1, 'sm' => 2]),
            ]);
    }

    /* ------------------------------------------------------------------ *
     *  Consultar
     * ------------------------------------------------------------------ */

    public function buscar(): void
    {
        $datos = $this->formularioBusqueda->getState();

        $this->reiniciarResultado();
        $this->consultado = true;

        $resultado = PacienteResource::consultarEnSavia(
            (string) $datos['tipo_documento'],
            (string) $datos['numero_documento'],
            $this,
        );

        if ($resultado === null) {
            // `consultarEnSavia()` ya avisó con el modal o con la notificación.
            return;
        }

        $this->atributos = $resultado->atributos;
        $this->contactoSavia = $resultado->contactoSavia;
        $this->cambios = $resultado->cambios();
        $this->pacienteExistenteId = $resultado->existente?->getKey();
        $this->registradoDesde = $resultado->existente?->created_at?->format('d/m/Y');

        // Un paciente que vuelve el mismo día casi nunca necesita otro turno.
        // Solo se puede saber si ya estaba registrado: si es nuevo, no tiene
        // visitas que mirar.
        $abierta = $resultado->existente === null
            ? null
            : VisitaAbierta::buscar($resultado->existente, auth()->user()?->sede);

        $this->visitaAbiertaId = $abierta?->ticket->getKey();
        $this->visitaAbiertaMotivo = $abierta?->motivo;

        $this->hojasSinTurno = $resultado->existente?->formulasSinTurno()->count() ?? 0;

        // El contacto lo manda SISPAM: si el paciente ya existe se precarga el
        // suyo, y lo que reporta Savia queda debajo como referencia.
        $contacto = $resultado->contactoParaElFormulario();

        $this->formularioVisita->fill([
            ...array_intersect_key($contacto, array_flip(Paciente::CAMPOS_CONTACTO)),
            'prioridad' => $this->prioridadSugerida(),
            'motivo_prioridad' => $this->motivoSugerido(),
        ]);
    }

    public function limpiar(): void
    {
        $this->reiniciarResultado();
        $this->formularioBusqueda->fill(['tipo_documento' => 'CC']);
    }

    private function reiniciarResultado(): void
    {
        $this->atributos = null;
        $this->contactoSavia = [];
        $this->cambios = [];
        $this->consultado = false;
        $this->pacienteExistenteId = null;
        $this->registradoDesde = null;
        $this->ticketGeneradoId = null;
        $this->visitaAbiertaId = null;
        $this->visitaAbiertaMotivo = null;
        $this->hojasSinTurno = 0;
        $this->formularioVisita->fill();
    }

    /* ------------------------------------------------------------------ *
     *  Generar el ticket
     * ------------------------------------------------------------------ */

    /**
     * Suma las hojas que se acaban de tomar a la visita que ya estaba abierta.
     *
     * El paciente volvió con otra hoja de la misma fórmula, así que no hace
     * otra fila: las fotos se cuelgan de su ticket y siguen la numeración
     * donde quedó.
     */
    public function sumarAVisitaAbierta(RegistrarVisita $registrar): void
    {
        $this->abrirVisita($registrar, $this->visitaAbierta?->ticket);
    }

    /**
     * Le da turno a la fórmula que quedó guardada sin ticket.
     *
     * Las fotos ya están en el disco desde la vez que la sede no tenía colas,
     * así que esto no pide el formulario: ni fotos nuevas ni volver a
     * confirmar el contacto, que ya se confirmó aquel día. Solo hace falta la
     * prioridad, y se toma la que esté puesta en pantalla.
     */
    public function completarFormulaSinTurno(RegistrarVisita $registrar): void
    {
        if ($this->pacienteExistenteId === null) {
            return;
        }

        $paciente = Paciente::find($this->pacienteExistenteId);

        if ($paciente === null) {
            return;
        }

        try {
            $resultado = $registrar->completarFormulaSinTurno(
                $paciente,
                $this->prioridadEnPantalla(),
                auth()->user(),
            );
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('No se pudo completar')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->hojasSinTurno = 0;

            return;
        }

        $this->hojasSinTurno = $paciente->formulasSinTurno()->count();

        if (! $resultado->tieneTicket()) {
            Notification::make()
                ->title('Todavía no se puede generar el turno')
                ->body($resultado->motivoSinTicket)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $this->ticketGeneradoId = $resultado->ticket->getKey();

        Notification::make()
            ->title("Turno {$resultado->ticket->turno} generado")
            ->body("La fórmula que había quedado sin turno ya tiene el suyo, con sus {$resultado->ordenesGuardadas} hojas.")
            ->success()
            ->send();
    }

    /**
     * La prioridad tal como está en el formulario, sin validarlo.
     *
     * Se lee del estado crudo a propósito: completar una fórmula que ya está
     * guardada no debe exigir fotos nuevas ni volver a confirmar el contacto,
     * que es lo que pediría `getState()`.
     *
     * @return array{prioridad: string, motivo_prioridad: ?string}
     */
    private function prioridadEnPantalla(): array
    {
        $prioridad = $this->visita['prioridad'] ?? Ticket::PRIORIDAD_NORMAL;

        return [
            'prioridad' => $prioridad,
            'motivo_prioridad' => $prioridad === Ticket::PRIORIDAD_PREFERENCIAL
                ? ($this->visita['motivo_prioridad'] ?? null)
                : null,
        ];
    }

    /**
     * Genera otro turno aunque el paciente ya tenga una visita viva.
     *
     * Es una decisión del orientador —trae una fórmula distinta—, así que
     * queda registrada: el ticket nuevo por sí solo no cuenta que había otro.
     */
    public function generarDeTodosModos(RegistrarVisita $registrar): void
    {
        $abierta = $this->visitaAbierta;

        if ($abierta !== null) {
            Auditoria::registrar(
                accion: Auditoria::ACCION_CREO,
                descripcion: 'Abrió una segunda visita para el paciente '
                    .$this->documento.', teniendo abierto el turno '.$abierta->ticket->turno,
                entidadTipo: 'ticket',
                entidadId: $abierta->ticket->getKey(),
            );
        }

        $this->abrirVisita($registrar, null);
    }

    public function generarTicket(RegistrarVisita $registrar): void
    {
        $this->abrirVisita($registrar, null);
    }

    /**
     * El camino común de los tres botones: valida, guarda y avisa.
     *
     * @param  Ticket|null  $visitaExistente  Cuando las hojas se suman a una visita ya abierta
     */
    private function abrirVisita(RegistrarVisita $registrar, ?Ticket $visitaExistente): void
    {
        // Ocultar el botón no impide una petición armada a mano: la misma
        // comprobación va en el servidor.
        if ($this->atributos === null) {
            Notification::make()
                ->title('Primero consulta el documento en Savia')
                ->body('Solo se abre una visita con una consulta exitosa para ese documento.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $datos = $this->formularioVisita->getState();

        // El orden de la lista es el orden en que quedaron las hojas en
        // pantalla, y eso es lo que se guarda como página.
        $ordenes = PacienteResource::separarOrdenesMedicas($datos);
        $datosTicket = PacienteResource::separarDatosDelTicket($datos);

        if ($ordenes === []) {
            Notification::make()
                ->title('Falta la fórmula médica')
                ->body('Carga al menos una foto o el archivo de la orden antes de generar el ticket.')
                ->danger()
                ->send();

            return;
        }

        try {
            $resultado = $registrar->handle(
                atributos: $this->atributos,
                contacto: $datos,
                ordenes: $ordenes,
                datosTicket: $datosTicket,
                usuario: auth()->user(),
                visitaExistente: $visitaExistente,
            );
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('No se pudo sumar a esa visita')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $this->ticketGeneradoId = $resultado->ticket?->getKey();

        if (! $resultado->tieneTicket()) {
            // El paciente y su fórmula quedaron guardados: perder la
            // orientación por un problema de configuración sería peor.
            Notification::make()
                ->title('El paciente quedó registrado, pero sin ticket')
                ->body($resultado->motivoSinTicket)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $hojas = $resultado->ordenesGuardadas === 1
            ? '1 hoja de la fórmula'
            : "{$resultado->ordenesGuardadas} hojas de la fórmula";

        if (! $resultado->turnoNuevo) {
            Notification::make()
                ->title("Se sumaron a la visita {$resultado->ticket->turno}")
                ->body("Quedaron {$hojas} más en ese ticket. El paciente conserva su turno.")
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title("Turno {$resultado->ticket->turno} generado")
            ->body(($resultado->pacienteNuevo
                ? 'El paciente quedó registrado en SISPAM. '
                : 'Se actualizó el paciente que ya estaba registrado. ')
                ."Quedaron {$hojas}.")
            ->success()
            ->send();
    }

    /**
     * Deja la pantalla lista para el siguiente paciente de la fila.
     */
    public function atenderOtro(): void
    {
        $this->limpiar();
    }

    /* ------------------------------------------------------------------ *
     *  Ayudas para la vista
     * ------------------------------------------------------------------ */

    public function getTicketProperty(): ?Ticket
    {
        return $this->ticketGeneradoId === null
            ? null
            : Ticket::with(['sede', 'paciente'])->find($this->ticketGeneradoId);
    }

    /**
     * La visita viva del paciente, ya rearmada desde el id que viaja en el
     * estado. Null cuando no hay ninguna o cuando el ticket ya no existe.
     */
    public function getVisitaAbiertaProperty(): ?VisitaAbierta
    {
        if ($this->visitaAbiertaId === null || $this->pacienteExistenteId === null) {
            return null;
        }

        $paciente = Paciente::find($this->pacienteExistenteId);

        if ($paciente === null) {
            return null;
        }

        // Se vuelve a buscar en vez de confiar en el id del estado: así, si el
        // ticket se cerró mientras el orientador llenaba el formulario, el
        // aviso desaparece solo.
        $abierta = VisitaAbierta::buscar($paciente, auth()->user()?->sede);

        return $abierta?->ticket->getKey() === $this->visitaAbiertaId ? $abierta : null;
    }

    public function getNombreCompletoProperty(): string
    {
        return trim(implode(' ', array_filter([
            $this->atributos['primer_nombre'] ?? null,
            $this->atributos['segundo_nombre'] ?? null,
            $this->atributos['primer_apellido'] ?? null,
            $this->atributos['segundo_apellido'] ?? null,
        ])));
    }

    public function getDocumentoProperty(): string
    {
        return trim(($this->atributos['tipo_documento'] ?? '').' '.($this->atributos['numero_documento'] ?? ''));
    }

    public function getEdadProperty(): ?int
    {
        $nacimiento = $this->atributos['fecha_nacimiento'] ?? null;

        if (blank($nacimiento)) {
            return null;
        }

        try {
            return Carbon::parse($nacimiento)->age;
        } catch (\Throwable) {
            // Una fecha ilegible no debe romper la pantalla.
            return null;
        }
    }

    public function getEstadoAfiliacionProperty(): ?string
    {
        return $this->atributos['estado_afiliacion'] ?? null;
    }

    public function getEstaActivoProperty(): bool
    {
        return Paciente::estadoEsActivo($this->estadoAfiliacion);
    }

    public function getColorEstadoProperty(): string
    {
        return Paciente::colorEstado($this->estadoAfiliacion);
    }

    public function getRegimenProperty(): ?string
    {
        return $this->atributos['regimen'] ?? null;
    }

    public function puedeImprimir(): bool
    {
        return (bool) auth()->user()?->puede('tickets.imprimir');
    }

    private function motivoSugerido(): ?string
    {
        return Ticket::motivoPrioridadSugerido(
            $this->atributos['fecha_nacimiento'] ?? null,
            $this->atributos['discapacidad'] ?? null,
        );
    }

    private function prioridadSugerida(): string
    {
        return Ticket::prioridadSugeridaPara(
            $this->atributos['fecha_nacimiento'] ?? null,
            $this->atributos['discapacidad'] ?? null,
        );
    }

    /**
     * Lo que Savia reporta para ese campo, cuando difiere de lo escrito. Es
     * referencia: nunca reemplaza lo que el orientador confirmó con el paciente.
     */
    private function referenciaSavia(Forms\Get $get, string $columna): ?string
    {
        $reportado = trim((string) ($this->contactoSavia[$columna] ?? ''));
        $actual = trim((string) $get($columna));

        return ($reportado !== '' && $reportado !== $actual)
            ? "Savia reporta: {$reportado}"
            : null;
    }
}
