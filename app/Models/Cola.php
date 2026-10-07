<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ColaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cola de atención de una sede. El prefijo arma el turno que ve el paciente.
 */
class Cola extends Model
{
    /** @use HasFactory<ColaFactory> */
    use Auditable, HasFactory;

    public const CAMPOS_AUDITADOS = ['sede_id', 'nombre', 'prefijo', 'activa', 'orden', 'atiende_alto_costo'];

    public const ETIQUETA_AUDITORIA = 'cola';

    protected $table = 'colas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sede_id',
        'nombre',
        'prefijo',
        'descripcion',
        'atiende_alto_costo',
        'activa',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'atiende_alto_costo' => 'boolean',
        ];
    }

    /**
     * El turno tal como se le muestra al paciente: «A-023».
     */
    public function formatearTurno(int $consecutivo): string
    {
        return $this->prefijo.'-'.str_pad((string) $consecutivo, 3, '0', STR_PAD_LEFT);
    }

    public function descripcionParaAuditoria(): string
    {
        return "la cola «{$this->nombre}» de {$this->sede?->nombre}";
    }

    /**
     * @return BelongsTo<Sede, $this>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    /**
     * @return HasMany<ContadorTurno, $this>
     */
    public function contadores(): HasMany
    {
        return $this->hasMany(ContadorTurno::class);
    }
}
