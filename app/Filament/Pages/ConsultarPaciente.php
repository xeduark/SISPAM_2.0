<?php

namespace App\Filament\Pages;

use App\Models\Auditoria;
use App\Models\Paciente;
use App\Services\Savia\RespuestaConsulta;
use App\Services\Savia\SaviaClient;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

/**
 * Consulta de un afiliado en Savia Salud EPS.
 *
 * Se escribe el documento y la pantalla muestra lo que devuelve el servicio,
 * sin necesidad de crear antes el paciente en SISPAM.
 *
 * ## Qué ve cada quien
 *
 * - `consultar_paciente.ver`: lo básico (`CAMPOS_BASICOS`).
 * - `consultar_paciente.ver_programas`: además, los programas especiales y
 *   RIAS. Con `ver`, es lo del rol CONSULTA, que usan cuentas compartidas.
 * - `consultar_paciente.ver_completo`: todo, programas especiales y datos de
 *   salud incluidos.
 *
 * Lo que no se puede ver se quita **en el servidor**, antes de guardarlo en
 * `$afiliado`: lo que está en una propiedad pública de Livewire viaja al
 * navegador aunque la vista no lo pinte.
 *
 * ## Registrar y actualizar
 *
 * Exigen `pacientes.crear` o `pacientes.editar`, y se comprueba **dentro de
 * `guardar()`**: un método de Livewire se puede llamar aunque el botón no se
 * haya pintado. Por la misma razón, lo que decide los permisos (`afiliado`,
 * `pacienteId`) está bloqueado con `#[Locked]`: el navegador no lo puede
 * cambiar.
 */
class ConsultarPaciente extends Page implements HasForms
{
    use InteractsWithForms;

    /**
     * Lo que ve quien solo tiene `consultar_paciente.ver`: identificación,
     * estado de la afiliación, régimen (con la categoría A, B o C si es
     * contributivo), municipio de afiliación e IPS primaria. Lista blanca: un
     * campo nuevo que agregue Savia no se ve hasta que alguien lo ponga aquí.
     *
     * El municipio y la categoría salen además en el encabezado, para verlos
     * sin bajar en la pantalla. La categoría solo es del contributivo: en otro
     * régimen no se manda, aunque Savia la traiga (`soloLoBasico()`).
     */
    public const CAMPOS_BASICOS = [
        'tipoDocumentoAfiliado',
        'documentoAfiliado',
        'primerNombreAfiliado',
        'segundoNombreAfiliado',
        'primerApellidoAfiliado',
        'segundoApellidoAfiliado',
        'fechaNacimientoAfiliado',
        'estadoAfiliacion',
        'causaEstado',
        'regimen',
        'categoriaIBC',
        'municipioAfiliacion',
        'ciudadAfiliacion',
        'departamentoAfiliacion',
        'ipsprimaria',
        'sedeIPSPrimaria',
    ];

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

    /** Afiliado devuelto por Savia, con los programas ya normalizados y recortado a lo que se puede ver. */
    #[Locked]
    public ?array $afiliado = null;

    /** @var array{tono: string, titulo: string, detalle: string}|null */
    #[Locked]
    public ?array $diagnostico = null;

    #[Locked]
    public bool $consultado = false;

    public bool $buscando = false;

    /** Id del paciente en SISPAM, si ya estaba registrado. */
    #[Locked]
    public ?int $pacienteId = null;

    #[Locked]
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
            $this->diagnostico = auth()->user()?->es_administrador
                ? [
                    'tono' => 'error',
                    'titulo' => 'La consulta a Savia no está configurada',
                    'detalle' => 'Faltan las credenciales SAVIA_USERNAME y SAVIA_PASSWORD en el .env.',
                ]
                : RespuestaConsulta::diagnosticoSinDetalle();
            $this->consultado = true;

            return;
        }

        $respuesta = $cliente->consultarAfiliado([
            'tipoDocumento' => $datos['tipo_documento'],
            'numeroDocumento' => $datos['numero_documento'],
        ]);

        $this->consultado = true;
        // El detalle técnico de una falla solo lo ve el administrador.
        $this->diagnostico = $respuesta->diagnosticoPara(auth()->user());

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

        // Lo que no se puede ver no sale del servidor.
        $this->afiliado = $this->veTodo ? $afiliado : self::soloLoBasico($afiliado, conProgramas: $this->veProgramas);

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

        // Si es crear o actualizar lo dice la base, no `pacienteId`: el
        // permiso no puede depender de lo que se pintó en la pantalla.
        $existe = Paciente::where('tipo_documento', $atributos['tipo_documento'])
            ->where('numero_documento', $atributos['numero_documento'] ?? null)
            ->exists();

        abort_unless(
            $this->puedeGuardar($existe),
            403,
            $existe ? 'No tienes permiso para actualizar pacientes.' : 'No tienes permiso para registrar pacientes.',
        );

        $atributos['consultado_en_savia_at'] = now();
        $atributos['codigo_respuesta_savia'] = '0';

        $paciente = Paciente::updateOrCreate(
            [
                'tipo_documento' => $atributos['tipo_documento'],
                'numero_documento' => $atributos['numero_documento'],
            ],
            $atributos,
        );

        $nuevo = ! $existe;

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

    /**
     * El afiliado recortado a `CAMPOS_BASICOS` (y a los programas, si los
     * puede ver), sin la categoría si el régimen no es contributivo.
     *
     * @param  array<string, mixed>  $afiliado
     * @return array<string, mixed>
     */
    private static function soloLoBasico(array $afiliado, bool $conProgramas): array
    {
        $campos = $conProgramas ? [...self::CAMPOS_BASICOS, 'programasEspeciales'] : self::CAMPOS_BASICOS;

        $basico = array_intersect_key($afiliado, array_flip($campos));

        if (! self::esContributivo($basico['regimen'] ?? null)) {
            unset($basico['categoriaIBC']);
        }

        return $basico;
    }

    /** El régimen llega como texto libre: se compara en minúsculas y sin tildes. */
    private static function esContributivo(mixed $regimen): bool
    {
        return is_scalar($regimen)
            && str_contains(Paciente::normalizarTexto((string) $regimen), 'contributivo');
    }

    private function reiniciarResultado(): void
    {
        $this->afiliado = null;
        $this->diagnostico = null;
        $this->consultado = false;
        $this->pacienteId = null;
        $this->registradoDesde = null;
    }

    /**
     * Registrar exige `pacientes.crear` y actualizar `pacientes.editar`.
     *
     * Las dos piden además ver todo lo de Savia: es lo que se guarda, y con
     * lo básico solo se borrarían los programas y lo demás que el paciente
     * ya tenía.
     */
    private function puedeGuardar(bool $existe): bool
    {
        $usuario = auth()->user();

        return (bool) $usuario?->puede($existe ? 'pacientes.editar' : 'pacientes.crear')
            && $usuario->puede('consultar_paciente.ver_completo');
    }

    /* ------------------------------------------------------------------ *
     *  Ayudas para la vista
     * ------------------------------------------------------------------ */

    /** Si ve todo lo que trae Savia o solo lo básico. */
    public function getVeTodoProperty(): bool
    {
        return (bool) auth()->user()?->puede('consultar_paciente.ver_completo');
    }

    /** Si ve los programas especiales y RIAS: con `ver_completo`, o solo esos con `ver_programas`. */
    public function getVeProgramasProperty(): bool
    {
        return $this->veTodo || (bool) auth()->user()?->puede('consultar_paciente.ver_programas');
    }

    public function getPuedeRegistrarProperty(): bool
    {
        return $this->pacienteId === null && $this->puedeGuardar(existe: false);
    }

    public function getPuedeActualizarProperty(): bool
    {
        return $this->pacienteId !== null && $this->puedeGuardar(existe: true);
    }

    public function getPuedeVerFichaProperty(): bool
    {
        return $this->pacienteId !== null && (bool) auth()->user()?->puede('pacientes.ver');
    }

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

    /**
     * «BELLO (ANTIOQUIA)»: el municipio de afiliación o, si no viene, la
     * ciudad. Null si Savia no mandó ninguno de los dos.
     */
    public function getMunicipioProperty(): ?string
    {
        $municipio = $this->textoDelAfiliado('municipioAfiliacion') ?: $this->textoDelAfiliado('ciudadAfiliacion');

        if ($municipio === '') {
            return null;
        }

        $departamento = $this->textoDelAfiliado('departamentoAfiliacion');

        return $departamento === '' ? $municipio : "{$municipio} ({$departamento})";
    }

    public function getEsContributivoProperty(): bool
    {
        return self::esContributivo($this->afiliado['regimen'] ?? null);
    }

    /**
     * «Categoría B», o null si Savia no la mandó. No se sabe si llega como
     * «B» o como «Categoría B»: si ya empieza por «categoría», va tal cual.
     */
    public function getCategoriaProperty(): ?string
    {
        $categoria = $this->textoDelAfiliado('categoriaIBC');

        if ($categoria === '') {
            return null;
        }

        return str_starts_with(Paciente::normalizarTexto($categoria), 'categoria')
            ? $categoria
            : "Categoría {$categoria}";
    }

    /** Un campo del afiliado como texto; cadena vacía si no vino. */
    private function textoDelAfiliado(string $campo): string
    {
        $valor = $this->afiliado[$campo] ?? null;

        return is_scalar($valor) ? trim((string) $valor) : '';
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
