<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La lectura de UNA orden médica. Cada medicamento cuelga de aquí, así que
 * sabe de qué fórmula salió.
 */
class Transcripcion extends Model
{
    use Auditable;

    protected $table = 'transcripciones';

    public const ESTADO_EN_COLA = 'en_cola';

    public const ESTADO_LEIDA = 'leida';

    public const ESTADO_EN_REVISION = 'en_revision';

    public const ESTADO_CONFIRMADA = 'confirmada';

    public const ESTADO_RECHAZADA = 'rechazada';

    public const ESTADO_FALLIDA = 'fallida';

    public const ESTADOS = [
        self::ESTADO_EN_COLA => 'En cola',
        self::ESTADO_LEIDA => 'Por revisar',
        self::ESTADO_EN_REVISION => 'En revisión',
        self::ESTADO_CONFIRMADA => 'Confirmada',
        self::ESTADO_RECHAZADA => 'Rechazada',
        self::ESTADO_FALLIDA => 'Falló la lectura',
    ];

    public const COLORES_ESTADO = [
        self::ESTADO_EN_COLA => 'gray',
        self::ESTADO_LEIDA => 'warning',
        self::ESTADO_EN_REVISION => 'info',
        self::ESTADO_CONFIRMADA => 'success',
        self::ESTADO_RECHAZADA => 'danger',
        self::ESTADO_FALLIDA => 'danger',
    ];

    public const CEDULA_COINCIDE = 'coincide';

    public const CEDULA_NO_ENCONTRADA = 'no_encontrada';

    /** No estaba en el texto leído y la transcriptora confirmó en el original que es del paciente. */
    public const CEDULA_REVISADA = 'revisada';

    public const VERIFICACIONES_CEDULA = [
        self::CEDULA_COINCIDE => 'Cédula encontrada',
        self::CEDULA_NO_ENCONTRADA => 'Cédula no encontrada',
        self::CEDULA_REVISADA => 'Revisada a mano',
    ];

    public const MOTIVOS_RECHAZO = [
        'ilegible' => 'Ilegible: no se puede leer',
        'otro_paciente' => 'No es de este paciente',
        'vencida' => 'Fórmula vencida o sin vigencia',
        'incompleta' => 'Incompleta (sin firma, médico o cantidades)',
        'duplicada' => 'Ya se transcribió',
        'otro' => 'Otro',
    ];

    /** Por qué no se dispensa UNA fórmula de la imagen (las demás siguen). Sale en la orden que firma el paciente. */
    public const MOTIVOS_RECHAZO_FORMULA = [
        'vencida' => 'Fórmula vencida',
        'sin_medico' => 'Sin médico, firma o registro médico',
        'ilegible' => 'Ilegible',
        'otro_paciente' => 'No es de este paciente',
        'ya_entregada' => 'Ya se entregó (duplicada)',
        'sin_autorizacion' => 'Requiere autorización o MIPRES que no trae',
        'otro' => 'Otro',
    ];

    /** Ni el texto leído ni los datos de la fórmula: son datos de salud. */
    public const CAMPOS_AUDITADOS = ['estado', 'verificacion_cedula', 'tomada_por', 'confirmada_por', 'motivo_rechazo'];

    public const ETIQUETA_AUDITORIA = 'transcripcion';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'soporte_id',
        'paciente_id',
        'ticket_id',
        'sede_id',
        'estado',
        'version',
        'texto_ocr',
        'verificacion_cedula',
        'error',
        'motivo_rechazo',
        'detalle_rechazo',
        'formula',
        'tomada_por',
        'tomada_en',
        'confirmada_por',
        'confirmada_en',
    ];

    protected function casts(): array
    {
        return [
            'texto_ocr' => 'encrypted',
            'formula' => 'array',
            'tomada_en' => 'datetime',
            'confirmada_en' => 'datetime',
        ];
    }

    public function descripcionParaAuditoria(): string
    {
        return 'la transcripción de una fórmula del paciente '.($this->paciente?->documento_completo ?? 'sin identificar');
    }

    /** @return BelongsTo<User, $this> */
    public function tomadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tomada_por');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmada_por');
    }

    /** @return list<array<string, mixed>> las fórmulas de la imagen (IPS, médico, CIE-10…) */
    public function formulas(): array
    {
        return $this->formula['formulas'] ?? [];
    }

    /** Se está corrigiendo una transcripción que ya estaba confirmada (fase 4). */
    public function enRectificacion(): bool
    {
        return isset($this->formula['rectificacion']);
    }

    /** @return list<int> números de las fórmulas de la imagen que no se dispensan */
    public function formulasRechazadas(): array
    {
        return collect($this->formulas())->filter(fn ($f) => ! empty($f['rechazada']))->pluck('numero')->map(fn ($n) => (int) $n)->values()->all();
    }

    /** @return BelongsTo<Soporte, $this> */
    public function soporte(): BelongsTo
    {
        return $this->belongsTo(Soporte::class);
    }

    /** @return BelongsTo<Paciente, $this> */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<Sede, $this> */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    /** @return HasMany<TranscripcionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(TranscripcionItem::class);
    }
}
