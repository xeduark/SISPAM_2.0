<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Último turno entregado por una cola en un día. Lo maneja
 * `App\Services\Turnos\GeneradorDeTurnos`; no se toca a mano.
 */
class ContadorTurno extends Model
{
    protected $table = 'contadores_turno';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'cola_id',
        'fecha',
        'ultimo',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'ultimo' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Cola, $this>
     */
    public function cola(): BelongsTo
    {
        return $this->belongsTo(Cola::class);
    }
}
