<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\Carbon;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El ticket de una visita del paciente.
 *
 * `numero` es único en todo el sistema y es lo que consulta el módulo de
 * entrega. `turno` es corto, por sede y día, y es lo que ve el paciente.
 */
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use Auditable, HasFactory;

    /* ----------------------------------------------------- Estados */

    /** Recién creado por el orientador: todavía sin medicamentos. */
    public const ESTADO_GENERADO = 'generado';

    /** Farmacia lo tomó y está alistando. */
    public const ESTADO_EN_ALISTAMIENTO = 'en_alistamiento';

    /** Listo para entregar. Entrega solo atiende este y `parcial`. */
    public const ESTADO_LISTO = 'listo';

    /** Quedaron faltantes de una entrega anterior. */
    public const ESTADO_PARCIAL = 'parcial';

    public const ESTADO_ENTREGADO = 'entregado';

    public const ESTADO_ANULADO = 'anulado';

    /** Nadie lo atendió y se cerró el día. */
    public const ESTADO_VENCIDO = 'vencido';

    public const ESTADOS = [
        self::ESTADO_GENERADO => 'Generado',
        self::ESTADO_EN_ALISTAMIENTO => 'En alistamiento',
        self::ESTADO_LISTO => 'Listo para entrega',
        self::ESTADO_PARCIAL => 'Entrega parcial',
        self::ESTADO_ENTREGADO => 'Entregado',
        self::ESTADO_ANULADO => 'Anulado',
        self::ESTADO_VENCIDO => 'Vencido',
    ];

    public const COLORES_ESTADO = [
        self::ESTADO_GENERADO => 'gray',
        self::ESTADO_EN_ALISTAMIENTO => 'warning',
        self::ESTADO_LISTO => 'success',
        self::ESTADO_PARCIAL => 'warning',
        self::ESTADO_ENTREGADO => 'success',
        self::ESTADO_ANULADO => 'danger',
        self::ESTADO_VENCIDO => 'danger',
    ];

    /** Los dos estados que el módulo de entrega sí atiende. */
    public const ESTADOS_ENTREGABLES = [self::ESTADO_LISTO, self::ESTADO_PARCIAL];

    /* ------------------------------------------- Estado en la sala */

    /*
     * El turno lleva su propio ciclo, aparte de `estado`.
     *
     * `estado` es el de la fórmula, y el módulo de entrega solo atiende
     * `listo` y `parcial`: si llamar al paciente lo moviera de ahí, el que
     * acabamos de llamar no se podría atender. Por eso llamar toca
     * `estado_sala` y nada más.
     */

    /** Alistado y esperando que lo llamen. */
    public const SALA_EN_ESPERA = 'en_espera';

    /** Se llamó por la pantalla de la sala. */
    public const SALA_LLAMADO = 'llamado';

    /** Se llamó y no apareció. Sale de la espera hasta que lo vuelvan a llamar. */
    public const SALA_AUSENTE = 'ausente';

    /** Ya pasó por la ventanilla: entrega registró la atención. */
    public const SALA_ATENDIDO = 'atendido';

    public const ESTADOS_SALA = [
        self::SALA_EN_ESPERA => 'En espera',
        self::SALA_LLAMADO => 'Llamado',
        self::SALA_AUSENTE => 'No se presentó',
        self::SALA_ATENDIDO => 'Atendido',
    ];

    public const COLORES_SALA = [
        self::SALA_EN_ESPERA => 'gray',
        self::SALA_LLAMADO => 'info',
        self::SALA_AUSENTE => 'danger',
        self::SALA_ATENDIDO => 'success',
    ];

    /* --------------------------------------------------- Prioridad */

    public const PRIORIDAD_NORMAL = 'normal';

    public const PRIORIDAD_PREFERENCIAL = 'preferencial';

    public const PRIORIDADES = [
        self::PRIORIDAD_NORMAL => 'Normal',
        self::PRIORIDAD_PREFERENCIAL => 'Preferencial',
    ];

    /** Por qué un paciente es preferencial. Se llaman de primeras. */
    public const MOTIVOS_PRIORIDAD = [
        'adulto_mayor' => 'Adulto mayor',
        'gestante' => 'Gestante',
        'discapacidad' => 'Discapacidad',
        'otro' => 'Otro',
    ];

    /** Desde esta edad se sugiere prioridad preferencial. */
    public const EDAD_ADULTO_MAYOR = 60;

    /**
     * Qué motivo de prioridad sugieren los datos que reporta Savia.
     *
     * Vive aquí, y no en el formulario, porque hay dos pantallas que abren
     * visitas —el asistente de Pacientes y Orientación— y las dos tienen que
     * sugerir lo mismo. Es solo una sugerencia: quien atiende decide.
     */
    public static function motivoPrioridadSugerido(?string $fechaNacimiento, ?string $discapacidad): ?string
    {
        if (filled($fechaNacimiento)) {
            try {
                if (Carbon::parse($fechaNacimiento)->age >= self::EDAD_ADULTO_MAYOR) {
                    return 'adulto_mayor';
                }
            } catch (\Throwable) {
                // Una fecha ilegible no debe romper el formulario.
            }
        }

        $discapacidad = Paciente::normalizarTexto((string) $discapacidad);

        return ($discapacidad !== '' && $discapacidad !== 'no') ? 'discapacidad' : null;
    }

    public static function prioridadSugeridaPara(?string $fechaNacimiento, ?string $discapacidad): string
    {
        return self::motivoPrioridadSugerido($fechaNacimiento, $discapacidad) !== null
            ? self::PRIORIDAD_PREFERENCIAL
            : self::PRIORIDAD_NORMAL;
    }

    public const CAMPOS_AUDITADOS = ['estado', 'cola_id', 'prioridad', 'alto_costo'];

    public const ETIQUETA_AUDITORIA = 'ticket';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'numero',
        'turno',
        'fecha',
        'paciente_id',
        'sede_id',
        'cola_id',
        'estado',
        'estado_sala',
        'prioridad',
        'motivo_prioridad',
        'alto_costo',
        'creado_por',
        'alistado_por',
        'alistado_en',
        'ventanilla_id',
        'llamado_por',
        'llamado_en',
        'cerrado_en',
        'motivo_anulacion',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'alto_costo' => 'boolean',
            'alistado_en' => 'datetime',
            'llamado_en' => 'datetime',
            'cerrado_en' => 'datetime',
        ];
    }

    public function descripcionParaAuditoria(): string
    {
        return "el ticket {$this->numero}";
    }

    /* ------------------------------------------------- Comodidades */

    public function esPreferencial(): bool
    {
        return $this->prioridad === self::PRIORIDAD_PREFERENCIAL;
    }

    public function estaEntregable(): bool
    {
        return in_array($this->estado, self::ESTADOS_ENTREGABLES, true);
    }

    /** Un ticket ya cerrado no se alista ni se anula. */
    public function estaCerrado(): bool
    {
        return in_array($this->estado, [
            self::ESTADO_ENTREGADO,
            self::ESTADO_ANULADO,
            self::ESTADO_VENCIDO,
        ], true);
    }

    public function sePuedeAlistar(): bool
    {
        return in_array($this->estado, [self::ESTADO_GENERADO, self::ESTADO_EN_ALISTAMIENTO], true);
    }

    /**
     * ¿Se puede llamar a este paciente?
     *
     * Hacen falta las dos cosas: que la fórmula esté lista (`estado`) y que el
     * turno no esté ya atendido (`estado_sala`). Un ausente sí se puede volver
     * a llamar; lo que no hace es entrar en «llamar al siguiente».
     */
    public function sePuedeLlamar(): bool
    {
        return $this->estaEntregable() && $this->estado_sala !== self::SALA_ATENDIDO;
    }

    /** Alistado y sin llamar todavía: es de los que siguen. */
    public function estaEnEspera(): bool
    {
        return $this->estaEntregable() && $this->estado_sala === self::SALA_EN_ESPERA;
    }

    public function fueLlamado(): bool
    {
        return $this->estado_sala === self::SALA_LLAMADO;
    }

    public function estaAusente(): bool
    {
        return $this->estado_sala === self::SALA_AUSENTE;
    }

    public function getEstadoSalaEtiquetaAttribute(): string
    {
        return self::ESTADOS_SALA[$this->estado_sala] ?? (string) $this->estado_sala;
    }

    public function getEstadoEtiquetaAttribute(): string
    {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

    /* ------------------------------------------------- Relaciones */

    /**
     * @return BelongsTo<Paciente, $this>
     */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    /**
     * @return BelongsTo<Sede, $this>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    /**
     * @return BelongsTo<Cola, $this>
     */
    public function cola(): BelongsTo
    {
        return $this->belongsTo(Cola::class);
    }

    /**
     * @return BelongsTo<Ventanilla, $this>
     */
    public function ventanilla(): BelongsTo
    {
        return $this->belongsTo(Ventanilla::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function alistadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'alistado_por');
    }

    /**
     * @return HasMany<TicketItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TicketItem::class);
    }

    /**
     * Los llamados del turno en la sala, el último primero.
     *
     * @return HasMany<Llamado, $this>
     */
    public function llamados(): HasMany
    {
        return $this->hasMany(Llamado::class)->latest('id');
    }

    /**
     * Las fórmulas de esta visita, en el orden en que las dejó el orientador.
     *
     * Van ordenadas desde la relación y no desde cada pantalla: la galería, el
     * modal de Alistar y la ficha del paciente tienen que mostrar las hojas en
     * el mismo orden, y ese orden es parte de leer la fórmula.
     *
     * @return HasMany<Soporte, $this>
     */
    public function soportes(): HasMany
    {
        return $this->hasMany(Soporte::class)
            ->orderBy('pagina')
            ->orderBy('id');
    }
}
