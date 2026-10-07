<?php

namespace App\Filament\Pages;

use App\Models\Auditoria;
use App\Models\Paciente;
use App\Services\Savia\SaviaClient;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Consulta de un afiliado en Savia Salud EPS.
 *
 * Se escribe el documento y la pantalla muestra todo lo que devuelve el
 * servicio, sin necesidad de crear antes el paciente en SISPAM.
 */
class ConsultarPaciente extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationLabel = 'Consultar paciente';

    protected static ?string $title = 'Consultar paciente';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.consultar-paciente';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->puede('consultar_paciente.ver');
    }

    /** @var array<string, mixed> */
    public array $datos = [];

    /** Afiliado devuelto por Savia, con los programas ya normalizados. */
    public ?array $afiliado = null;

    /** @var array{tono: string, titulo: string, detalle: string}|null */
    public ?array $diagnostico = null;

    public bool $consultado = false;

    public bool $buscando = false;

    /** Id del paciente en SISPAM, si ya estaba registrado. */
    public ?int $pacienteId = null;

    public ?string $registradoDesde = null;

    public function mount(): void
    {
        $this->form->fill(['tipo_documento' => 'CC']);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('tipo_documento')
                    ->label('Tipo de documento')
                    ->options(config('savia.tipos_documento'))
                    ->default('CC')
                    ->required()
                    ->selectablePlaceholder(false),
                Forms\Components\TextInput::make('numero_documento')
                    ->label('Número de documento')
                    ->placeholder('Escribe la cédula y presiona Enter')
                    ->required()
                    ->maxLength(20)
                    // Las mismas reglas que exige el servicio (códigos -1003 y -1004).
                    ->regex('/^[A-Za-z0-9\-]+$/')
                    ->validationMessages([
                        'regex' => 'El número de documento solo admite letras, números y guion.',
                    ])
                    ->autofocus(),
            ])
            // Dos campos en dos columnas: con tres quedaba un tercio vacío
            // y los campos apretados contra la izquierda.
            ->columns(['default' => 1, 'sm' => 2])
            ->statePath('datos');
    }

    /**
     * Consulta el documento en Savia y deja el resultado listo para la vista.
     */
    public function buscar(): void
    {
        $datos = $this->form->getState();

        $this->reiniciarResultado();

        $cliente = app(SaviaClient::class);

        if (! $cliente->configurado()) {
            $this->diagnostico = [
                'tono' => 'error',
                'titulo' => 'La consulta a Savia no está configurada',
                'detalle' => 'Faltan las credenciales SAVIA_USERNAME y SAVIA_PASSWORD. Comunícate con un administrador.',
            ];
            $this->consultado = true;

            return;
        }

        $respuesta = $cliente->consultarAfiliado([
            'tipoDocumento' => $datos['tipo_documento'],
            'numeroDocumento' => $datos['numero_documento'],
        ]);

        $this->consultado = true;
        $this->diagnostico = $respuesta->diagnostico();

        $afiliado = $respuesta->primerAfiliado();

        // Queda el rastro de quién consultó qué documento, sin datos del afiliado.
        Auditoria::registrar(
            accion: Auditoria::ACCION_CONSULTO_SAVIA,
            descripcion: 'Consultó en Savia el documento '
                .strtoupper((string) $datos['tipo_documento']).' '.$datos['numero_documento']
                .($afiliado === null ? ' (sin resultado)' : ''),
            entidadTipo: 'paciente',
        );

        if (! $respuesta->exitosa() || $afiliado === null) {
            // Cuando el servicio responde bien pero sin afiliados, el mensaje
            // del diagnóstico no lo dice: se aclara aquí.
            if ($respuesta->exitosa()) {
                $this->diagnostico = [
                    'tono' => 'warn',
                    'titulo' => 'El afiliado no aparece en Savia',
                    'detalle' => 'El servicio respondió correctamente pero no devolvió ningún afiliado con ese documento.',
                ];
            }

            return;
        }

        $this->afiliado = $afiliado;

        $paciente = Paciente::where('tipo_documento', $afiliado['tipoDocumentoAfiliado'] ?? $datos['tipo_documento'])
            ->where('numero_documento', $afiliado['documentoAfiliado'] ?? $datos['numero_documento'])
            ->first();

        $this->pacienteId = $paciente?->id;
        $this->registradoDesde = $paciente?->created_at?->format('d/m/Y');
    }

    /**
     * Guarda (o actualiza) en SISPAM el afiliado que se está viendo.
     */
    public function guardar(): void
    {
        if ($this->afiliado === null) {
            return;
        }

        $atributos = Paciente::atributosDesdeSavia($this->afiliado);
        $atributos['consultado_en_savia_at'] = now();
        $atributos['codigo_respuesta_savia'] = '0';

        $paciente = Paciente::updateOrCreate(
            [
                'tipo_documento' => $atributos['tipo_documento'],
                'numero_documento' => $atributos['numero_documento'],
            ],
            $atributos,
        );

        $nuevo = $this->pacienteId === null;

        $this->pacienteId = $paciente->id;
        $this->registradoDesde = $paciente->created_at?->format('d/m/Y');

        Notification::make()
            ->title($nuevo ? 'Paciente registrado en SISPAM' : 'Paciente actualizado en SISPAM')
            ->success()
            ->send();
    }

    public function limpiar(): void
    {
        $this->reiniciarResultado();
        $this->consultado = false;
        $this->form->fill(['tipo_documento' => 'CC']);
    }

    private function reiniciarResultado(): void
    {
        $this->afiliado = null;
        $this->diagnostico = null;
        $this->consultado = false;
        $this->pacienteId = null;
        $this->registradoDesde = null;
    }

    /* ------------------------------------------------------------------ *
     *  Ayudas para la vista
     * ------------------------------------------------------------------ */

    public function getNombreCompletoProperty(): string
    {
        return trim(implode(' ', array_filter([
            $this->afiliado['primerNombreAfiliado'] ?? null,
            $this->afiliado['segundoNombreAfiliado'] ?? null,
            $this->afiliado['primerApellidoAfiliado'] ?? null,
            $this->afiliado['segundoApellidoAfiliado'] ?? null,
        ])));
    }

    public function getEstadoAfiliacionProperty(): ?string
    {
        return $this->afiliado['estadoAfiliacion'] ?? null;
    }

    public function getEstaActivoProperty(): bool
    {
        return Paciente::estadoEsActivo($this->estadoAfiliacion);
    }

    public function getColorEstadoProperty(): string
    {
        return Paciente::colorEstado($this->estadoAfiliacion);
    }

    /**
     * Los campos del afiliado agrupados y con su etiqueta en español, según
     * la especificación V3. Solo se devuelven los grupos que traen algún dato.
     *
     * @return array<string, array<string, string>>
     */
    public function getGruposProperty(): array
    {
        if ($this->afiliado === null) {
            return [];
        }

        $grupos = [];

        foreach ((array) config('savia.grupos', []) as $titulo => $campos) {
            $valores = [];

            foreach ($campos as $campo => $etiqueta) {
                $valor = $this->afiliado[$campo] ?? null;
                $valor = is_array($valor) ? json_encode($valor, JSON_UNESCAPED_UNICODE) : trim((string) ($valor ?? ''));

                if ($valor !== '') {
                    $valores[$etiqueta] = $valor;
                }
            }

            if ($valores !== []) {
                $grupos[$titulo] = $valores;
            }
        }

        return $grupos;
    }

    /**
     * @return array<int, array{tipo: string, descripcion: string}>
     */
    public function getProgramasProperty(): array
    {
        $programas = [];

        foreach ($this->afiliado['programasEspeciales'] ?? [] as $programa) {
            if (is_array($programa)) {
                $programas[] = [
                    'tipo' => trim((string) ($programa['tipo'] ?? 'Programa')),
                    'descripcion' => trim((string) ($programa['descripcion'] ?? '—')),
                ];
            }
        }

        return $programas;
    }

    /**
     * Campos que devolvió el servicio y que la V3 no documenta.
     *
     * @return array<string, string>
     */
    public function getCamposNuevosProperty(): array
    {
        if ($this->afiliado === null) {
            return [];
        }

        $conocidos = ['programasEspeciales', 'programas'];
        foreach ((array) config('savia.grupos', []) as $campos) {
            $conocidos = array_merge($conocidos, array_keys($campos));
        }

        $nuevos = [];
        foreach (array_diff_key($this->afiliado, array_flip($conocidos)) as $campo => $valor) {
            $texto = is_array($valor) ? json_encode($valor, JSON_UNESCAPED_UNICODE) : trim((string) ($valor ?? ''));

            if ($texto !== '') {
                $nuevos[$campo] = $texto;
            }
        }

        return $nuevos;
    }
}
