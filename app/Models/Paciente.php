<?php

namespace App\Models;

use Database\Factories\PacienteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class Paciente extends Model
{
    /** @use HasFactory<PacienteFactory> */
    use HasFactory;

    /**
     * Nombre del atributo de Savia => columna de la tabla.
     *
     * Los nombres vienen de la especificación V3 del servicio (ver la
     * agrupación en config/savia.php). Lo que no esté en este mapa se guarda
     * en `datos_adicionales`, así ningún campo se pierde.
     *
     * @var array<string, string>
     */
    public const CAMPOS_SAVIA = [
        // Identificación
        'tipoDocumentoAfiliado' => 'tipo_documento',
        'documentoAfiliado' => 'numero_documento',
        'primerNombreAfiliado' => 'primer_nombre',
        'segundoNombreAfiliado' => 'segundo_nombre',
        'primerApellidoAfiliado' => 'primer_apellido',
        'segundoApellidoAfiliado' => 'segundo_apellido',
        'fechaNacimientoAfiliado' => 'fecha_nacimiento',
        'sexoAfiliado' => 'sexo',
        'generoIdentificacion' => 'genero_identificacion',
        'estadoCivil' => 'estado_civil',

        // Condición de salud
        'discapacidad' => 'discapacidad',
        'tipoDiscapacidad' => 'tipo_discapacidad',
        'victimaLey1448' => 'victima_ley_1448',

        // Afiliación
        'estadoAfiliacion' => 'estado_afiliacion',
        'regimen' => 'regimen',
        'tipoAfiliado' => 'tipo_afiliado',
        'modalidadSubsidio' => 'modalidad_subsidio',
        'causaEstado' => 'causa_estado',
        'fechaAfiliacionSGSSS' => 'fecha_afiliacion_sgsss',
        'fechaAfiliacionEntidad' => 'fecha_afiliacion_entidad',
        'fechaSuspension' => 'fecha_suspension',
        'fechaRetiro' => 'fecha_retiro',
        'consecutivoBDUA' => 'consecutivo_bdua',
        'codigoEntidad' => 'codigo_entidad',

        // Núcleo familiar
        'tipoDocumentoCabezaFamilia' => 'tipo_documento_cabeza_familia',
        'documentoCabezaFamilia' => 'documento_cabeza_familia',
        'parentescoCabezaFamilia' => 'parentesco_cabeza_familia',

        // Clasificación socioeconómica
        'grupoPoblacional' => 'grupo_poblacional',
        'nivelSisben' => 'nivel_sisben',
        'puntajeSisben' => 'puntaje_sisben',
        'grupoSisben' => 'grupo_sisben',

        // Residencia (lo administrativo; el contacto lo confirma SISPAM, ver CONTACTO_SAVIA)
        'comuna' => 'comuna',
        'municipioAfiliacion' => 'municipio_afiliacion',
        'departamentoAfiliacion' => 'departamento_afiliacion',
        'email' => 'email',

        // IPS y portabilidad
        'codigoIPS' => 'codigo_ips',
        'ipsprimaria' => 'ips_primaria',
        'sedeIPSPrimaria' => 'sede_ips_primaria',
        'tipoPortabilidad' => 'tipo_portabilidad',

        // Observación
        'observacion' => 'observacion',
    ];

    /**
     * Contacto: datos propios de SISPAM, confirmados con el paciente.
     *
     * A propósito NO están en CAMPOS_SAVIA. Como `atributosDesdeSavia()` solo
     * devuelve lo que está en ese mapa, ninguna consulta —ni la del formulario,
     * ni «Actualizar desde Savia», ni nada que se agregue después— puede
     * sobrescribir lo que el personal confirmó con el paciente.
     *
     * @var list<string>
     */
    public const CAMPOS_CONTACTO = [
        'telefono_movil',
        'telefono',
        'direccion',
        'barrio',
        'ciudad_residencia',
        'indicaciones_entrega',
    ];

    /**
     * Lo que Savia reporta como contacto. Sirve para precargar el formulario
     * de un paciente nuevo y para mostrarse como referencia en uno que ya está
     * registrado, pero nunca se guarda directamente.
     *
     * @var array<string, string>
     */
    public const CONTACTO_SAVIA = [
        'telefonoMovil' => 'telefono_movil',
        'telefono' => 'telefono',
        'direccion' => 'direccion',
        'barrio' => 'barrio',
        'descripcionCiudadResidencia' => 'ciudad_residencia',
    ];

    /** Columnas que guardan fechas y necesitan normalizarse antes de escribirse. */
    public const COLUMNAS_FECHA = [
        'fecha_nacimiento',
        'fecha_afiliacion_sgsss',
        'fecha_afiliacion_entidad',
        'fecha_suspension',
        'fecha_retiro',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tipo_documento',
        'numero_documento',
        'primer_nombre',
        'segundo_nombre',
        'primer_apellido',
        'segundo_apellido',
        'fecha_nacimiento',
        'sexo',
        'genero_identificacion',
        'estado_civil',
        'discapacidad',
        'tipo_discapacidad',
        'victima_ley_1448',
        'estado_afiliacion',
        'regimen',
        'tipo_afiliado',
        'modalidad_subsidio',
        'causa_estado',
        'fecha_afiliacion_sgsss',
        'fecha_afiliacion_entidad',
        'fecha_suspension',
        'fecha_retiro',
        'consecutivo_bdua',
        'codigo_entidad',
        'tipo_documento_cabeza_familia',
        'documento_cabeza_familia',
        'parentesco_cabeza_familia',
        'grupo_poblacional',
        'nivel_sisben',
        'puntaje_sisben',
        'grupo_sisben',
        'direccion',
        'barrio',
        'comuna',
        'ciudad_residencia',
        'municipio_afiliacion',
        'departamento_afiliacion',
        'telefono',
        'telefono_movil',
        'email',
        'codigo_ips',
        'ips_primaria',
        'sede_ips_primaria',
        'tipo_portabilidad',
        'programas',
        'datos_adicionales',
        'indicaciones_entrega',
        'observacion',
        'codigo_respuesta_savia',
        'consultado_en_savia_at',
        'contacto_confirmado_at',
        'contacto_confirmado_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'fecha_afiliacion_sgsss' => 'date',
            'fecha_afiliacion_entidad' => 'date',
            'fecha_suspension' => 'date',
            'fecha_retiro' => 'date',
            'programas' => 'array',
            'datos_adicionales' => 'array',
            'consultado_en_savia_at' => 'datetime',
            'contacto_confirmado_at' => 'datetime',
        ];
    }

    /**
     * Deja rastro de los cambios de afiliación pase lo que pase: el formulario,
     * la edición y «Actualizar desde Savia» pasan todos por aquí.
     */
    protected static function booted(): void
    {
        static::updating(function (Paciente $paciente): void {
            $vigilados = [
                'estado_afiliacion' => 'estado de afiliación',
                'regimen' => 'régimen',
            ];

            foreach ($vigilados as $columna => $nombre) {
                if (! $paciente->isDirty($columna)) {
                    continue;
                }

                // Solo el documento, los dos valores y quién lo hizo:
                // nunca la respuesta del servicio ni datos del paciente.
                Log::channel(config('logging.default'))->info("Cambio de {$nombre} del paciente", [
                    'usuario' => auth()->id(),
                    'tipoDocumento' => $paciente->tipo_documento,
                    'numeroDocumento' => $paciente->numero_documento,
                    'anterior' => $paciente->getOriginal($columna),
                    'nuevo' => $paciente->getAttribute($columna),
                ]);
            }
        });
    }

    /**
     * Quién confirmó por última vez el teléfono y la dirección con el paciente.
     *
     * @return BelongsTo<User, $this>
     */
    public function contactoConfirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contacto_confirmado_por');
    }

    /**
     * Órdenes médicas cargadas por el orientador, una por cada atención.
     *
     * @return HasMany<Soporte, $this>
     */
    public function soportes(): HasMany
    {
        return $this->hasMany(Soporte::class);
    }

    /**
     * Entregas de medicamentos registradas para este paciente.
     *
     * @return HasMany<Entrega, $this>
     */
    public function entregas(): HasMany
    {
        return $this->hasMany(Entrega::class);
    }

    public function tieneContactoConfirmado(): bool
    {
        return $this->contacto_confirmado_at !== null;
    }

    public function getNombreCompletoAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->primer_nombre,
            $this->segundo_nombre,
            $this->primer_apellido,
            $this->segundo_apellido,
        ])));
    }

    public function getDocumentoCompletoAttribute(): string
    {
        return trim("{$this->tipo_documento} {$this->numero_documento}");
    }

    /**
     * Traduce un afiliado de Savia a los atributos de la tabla.
     *
     * Lo que no tiene columna propia queda en `datos_adicionales`, incluidos
     * los campos que Savia devuelva y la V3 no documente.
     *
     * @param  array<string, mixed>  $afiliado  Un afiliado ya normalizado por RespuestaConsulta
     * @return array<string, mixed>
     */
    public static function atributosDesdeSavia(array $afiliado): array
    {
        $atributos = [];

        foreach (self::CAMPOS_SAVIA as $campoSavia => $columna) {
            if (! array_key_exists($campoSavia, $afiliado)) {
                continue;
            }

            $valor = $afiliado[$campoSavia];
            $valor = is_string($valor) ? trim($valor) : $valor;

            if (in_array($columna, self::COLUMNAS_FECHA, true)) {
                $atributos[$columna] = self::normalizarFecha(is_scalar($valor) ? (string) $valor : null);

                continue;
            }

            $atributos[$columna] = ($valor === '' || $valor === null) ? null : $valor;
        }

        $atributos['tipo_documento'] = strtoupper((string) ($atributos['tipo_documento'] ?? ''));

        $programas = [];
        foreach ($afiliado['programasEspeciales'] ?? [] as $programa) {
            if (is_array($programa)) {
                $programas[] = [
                    'tipo' => trim((string) ($programa['tipo'] ?? '')),
                    'descripcion' => trim((string) ($programa['descripcion'] ?? '')),
                ];
            }
        }
        $atributos['programas'] = $programas;

        $atributos['datos_adicionales'] = self::datosAdicionales($afiliado);

        return $atributos;
    }

    /**
     * El contacto tal como lo reporta Savia. No se guarda directamente: sirve
     * para precargar el formulario de un paciente nuevo y para mostrarse como
     * referencia junto al que ya tiene SISPAM.
     *
     * @param  array<string, mixed>  $afiliado
     * @return array<string, string|null>
     */
    public static function contactoDesdeSavia(array $afiliado): array
    {
        $contacto = [];

        foreach (self::CONTACTO_SAVIA as $campoSavia => $columna) {
            $valor = trim((string) ($afiliado[$campoSavia] ?? ''));

            $contacto[$columna] = $valor === '' ? null : $valor;
        }

        // Savia no reporta indicaciones de entrega: eso solo lo sabe el paciente.
        $contacto['indicaciones_entrega'] = null;

        return $contacto;
    }

    /**
     * Columna => etiqueta en español, tomada de la especificación V3.
     *
     * @return array<string, string>
     */
    public static function etiquetasDeColumnas(): array
    {
        $etiquetasSavia = [];
        foreach ((array) config('savia.grupos', []) as $campos) {
            $etiquetasSavia += $campos;
        }

        $etiquetas = [];
        foreach (self::CAMPOS_SAVIA as $campoSavia => $columna) {
            $etiquetas[$columna] = $etiquetasSavia[$campoSavia] ?? $columna;
        }

        return $etiquetas;
    }

    /**
     * Qué cambiaría en este paciente si se guardaran los atributos que acaba
     * de devolver Savia, para mostrárselo al usuario antes de guardar.
     *
     * @param  array<string, mixed>  $atributos
     * @return array<string, string> Etiqueta => «antes → ahora»
     */
    public function cambiosFrenteA(array $atributos): array
    {
        $etiquetas = self::etiquetasDeColumnas();
        $cambios = [];

        foreach ($atributos as $columna => $nuevo) {
            // Solo los datos de Savia: el contacto no viene de ahí, y programas
            // o datos_adicionales no se leen como «antes → ahora».
            if (! isset($etiquetas[$columna])) {
                continue;
            }

            $antes = self::comoTexto($this->getAttribute($columna));
            $ahora = self::comoTexto($nuevo);

            if ($antes === $ahora) {
                continue;
            }

            $cambios[$etiquetas[$columna]] = ($antes ?: '—').' → '.($ahora ?: '—');
        }

        return $cambios;
    }

    /**
     * Normaliza un valor para compararlo: las fechas llegan como Carbon desde
     * el modelo y como texto desde la respuesta del servicio.
     */
    private static function comoTexto(mixed $valor): string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }

        return trim((string) ($valor ?? ''));
    }

    /**
     * Los campos de la respuesta que no tienen columna propia, con su etiqueta
     * en español cuando la V3 la documenta.
     *
     * @param  array<string, mixed>  $afiliado
     * @return array<string, string>
     */
    private static function datosAdicionales(array $afiliado): array
    {
        $conColumna = array_keys(self::CAMPOS_SAVIA);
        $conColumna[] = 'programasEspeciales';
        $conColumna[] = 'programas';

        $etiquetas = [];
        foreach ((array) config('savia.grupos', []) as $campos) {
            $etiquetas += $campos;
        }

        $adicionales = [];
        foreach ($afiliado as $campo => $valor) {
            if (in_array($campo, $conColumna, true)) {
                continue;
            }

            $texto = is_array($valor)
                ? json_encode($valor, JSON_UNESCAPED_UNICODE)
                : trim((string) ($valor ?? ''));

            if ($texto === '') {
                continue;
            }

            $adicionales[$etiquetas[$campo] ?? $campo] = $texto;
        }

        return $adicionales;
    }

    /**
     * El servicio no es consistente con el formato de las fechas: unas llegan
     * como yyyy-MM-dd y otras como dd/MM/yyyy, a veces con hora.
     */
    public static function normalizarFecha(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd/m/Y H:i:s', 'Y-m-d H:i:s'] as $formato) {
            $fecha = \DateTimeImmutable::createFromFormat($formato, $valor);

            if ($fecha !== false && $fecha->format($formato) === $valor) {
                return $fecha->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * Si el estado de afiliación habilita la atención del paciente.
     */
    public function estaActivo(): bool
    {
        return self::estadoEsActivo($this->estado_afiliacion);
    }

    public static function estadoEsActivo(?string $estado): bool
    {
        return in_array(self::normalizarTexto($estado), (array) config('savia.estados_activos', []), true);
    }

    /**
     * Color del badge del estado, según la paleta institucional.
     */
    public static function colorEstado(?string $estado): string
    {
        $colores = (array) config('savia.colores_estado', []);

        return (string) ($colores[self::normalizarTexto($estado)] ?? 'gray');
    }

    /**
     * Minúsculas y sin tildes, para comparar el texto libre que devuelve Savia.
     */
    public static function normalizarTexto(?string $valor): string
    {
        $valor = mb_strtolower(trim((string) $valor), 'UTF-8');

        return strtr($valor, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ñ' => 'n',
        ]);
    }
}
