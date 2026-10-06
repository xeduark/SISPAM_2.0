<?php

namespace App\Models;

use Database\Factories\AuditoriaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Throwable;

/**
 * Una línea del rastro de auditoría. No se edita ni se borra.
 *
 * Nunca guarda datos de salud: del paciente queda su documento, que es lo
 * mínimo para saber a quién se le hizo algo.
 */
class Auditoria extends Model
{
    /** @use HasFactory<AuditoriaFactory> */
    use HasFactory;

    public const ACCION_INGRESO = 'ingreso';

    public const ACCION_CERRO_SESION = 'cerro_sesion';

    public const ACCION_CREO = 'creo';

    public const ACCION_ACTUALIZO = 'actualizo';

    public const ACCION_ELIMINO = 'elimino';

    public const ACCION_CONSULTO_SAVIA = 'consulto_savia';

    public const ACCION_DESCARGO_ORDEN = 'descargo_orden';

    /**
     * Entró a la galería de fórmulas de un paciente.
     *
     * Es distinto de abrir una: ver las miniaturas no es leer la fórmula. Se
     * separan para que el rastro de quién sí la leyó no quede enterrado entre
     * una línea por cada imagen que se pintó en pantalla.
     */
    public const ACCION_VIO_GALERIA = 'vio_galeria';

    /** El cierre del día venció los tickets que nadie alcanzó a atender. */
    public const ACCION_CERRO_DIA = 'cerro_dia';

    /** Etiquetas en español para mostrar la acción en pantalla. */
    public const ACCIONES = [
        self::ACCION_INGRESO => 'Ingresó',
        self::ACCION_CERRO_SESION => 'Cerró sesión',
        self::ACCION_CREO => 'Creó',
        self::ACCION_ACTUALIZO => 'Actualizó',
        self::ACCION_ELIMINO => 'Eliminó',
        self::ACCION_CONSULTO_SAVIA => 'Consultó en Savia',
        self::ACCION_DESCARGO_ORDEN => 'Abrió una orden médica',
        self::ACCION_VIO_GALERIA => 'Vio la galería de fórmulas',
        self::ACCION_CERRO_DIA => 'Cerró el día',
    ];

    /** Color del badge por acción, dentro de la paleta institucional. */
    public const COLORES = [
        self::ACCION_INGRESO => 'success',
        self::ACCION_CERRO_SESION => 'gray',
        self::ACCION_CREO => 'success',
        self::ACCION_ACTUALIZO => 'info',
        self::ACCION_ELIMINO => 'danger',
        self::ACCION_CONSULTO_SAVIA => 'info',
        self::ACCION_DESCARGO_ORDEN => 'warning',
        self::ACCION_VIO_GALERIA => 'gray',
        self::ACCION_CERRO_DIA => 'warning',
    ];

    /** Se escribe una vez y queda; por eso no hay `updated_at`. */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'usuario_id',
        'usuario_nombre',
        'usuario_documento',
        'sede_id',
        'accion',
        'entidad_tipo',
        'entidad_id',
        'descripcion',
        'cambios',
        'ip',
        'navegador',
    ];

    protected function casts(): array
    {
        return [
            'cambios' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Registra una línea de auditoría.
     *
     * Nunca interrumpe lo que el usuario estaba haciendo: si el registro
     * falla, la operación de negocio sigue su curso.
     *
     * `$sedeId` es la sede del hecho. Casi siempre es la del usuario y se
     * deduce sola; se pasa a mano cuando lo que se registra no pasa en la sede
     * de quien lo ejecuta —el cierre del día, por ejemplo, que lo corre una
     * tarea programada sin usuario y cierra sede por sede.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>|null  $cambios
     */
    public static function registrar(
        string $accion,
        string $descripcion,
        ?string $entidadTipo = null,
        ?int $entidadId = null,
        ?array $cambios = null,
        ?User $usuario = null,
        ?int $sedeId = null,
    ): ?self {
        try {
            $usuario ??= auth()->user();

            return static::create([
                'usuario_id' => $usuario?->getKey(),
                'usuario_nombre' => $usuario?->nombre_completo ?? 'Sistema',
                'usuario_documento' => $usuario?->documento,
                'sede_id' => $sedeId ?? $usuario?->sede_id,
                'accion' => $accion,
                'entidad_tipo' => $entidadTipo,
                'entidad_id' => $entidadId,
                'descripcion' => mb_substr($descripcion, 0, 255),
                'cambios' => $cambios ?: null,
                'ip' => static::peticion()?->ip(),
                'navegador' => mb_substr((string) static::peticion()?->userAgent(), 0, 255) ?: null,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * La petición actual, o null en consola y pruebas sin petición.
     */
    private static function peticion(): ?Request
    {
        return app()->runningInConsole() && ! app()->runningUnitTests()
            ? null
            : request();
    }

    public function getAccionEtiquetaAttribute(): string
    {
        return self::ACCIONES[$this->accion] ?? $this->accion;
    }

    public function getColorAttribute(): string
    {
        return self::COLORES[$this->accion] ?? 'gray';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * @return BelongsTo<Sede, $this>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }
}
