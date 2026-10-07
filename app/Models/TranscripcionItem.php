<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un medicamento leído de una fórmula, con la sugerencia del inventario.
 */
class TranscripcionItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'formula',
        'texto_prescrito',
        'concentracion',
        'posologia',
        'codigo_inventario',
        'agrupador',
        'nombre_inventario',
        'similitud',
        'alertas',
        'cantidad_total',
        'duracion_dias',
        'meses',
        'entrega_mes',
        'cantidad_mes',
        'editado_por',
        'revisado',
    ];

    protected function casts(): array
    {
        return [
            'alertas' => 'array',
            'similitud' => 'integer',
            'formula' => 'integer',
            'revisado' => 'boolean',
        ];
    }

    /** @return BelongsTo<Transcripcion, $this> */
    public function transcripcion(): BelongsTo
    {
        return $this->belongsTo(Transcripcion::class);
    }
}
