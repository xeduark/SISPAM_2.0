<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Último turno entregado por una sede en un día. Lo maneja
 * `App\Services\Turnos\GeneradorDeTurnos`; no se toca a mano.
 *
 * Cuenta la **sede**, no la cola: desde que el turno es solo el consecutivo
 * («0060»), dos colas contando aparte sacarían el mismo número el mismo día.
 */
class ContadorTurno extends Model
{
    protected $table = 'contadores_turno';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sede_id',
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
     * @return BelongsTo<Sede, $this>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }
}
