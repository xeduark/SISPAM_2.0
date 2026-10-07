<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Orden médica que carga el orientador al atender al paciente.
 */
class Soporte extends Model
{
    use Auditable;

    /**
     * Vacío a propósito: de la orden médica solo interesa saber que se cargó
     * y para qué paciente. Ni la ruta del archivo ni la marca de alto costo
     * u oncológico entran al rastro, porque son datos de salud.
     */
    public const CAMPOS_AUDITADOS = [];

    public const ETIQUETA_AUDITORIA = 'orden_medica';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ticket_id',
        'orden_medica',
        'alto_costo_oncologico',
        'cargado_por',
    ];

    protected function casts(): array
    {
        return [
            'alto_costo_oncologico' => 'boolean',
        ];
    }

    public function descripcionParaAuditoria(): string
    {
        return 'una orden médica del paciente '.($this->paciente?->documento_completo ?? 'sin identificar');
    }

    /**
     * El ticket de la visita en la que se cargó esta orden.
     *
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<Paciente, $this>
     */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cargadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cargado_por');
    }
}
