<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntregaItem extends Model
{
    public const RESULTADO_ENTREGADO = 'entregado';

    public const RESULTADO_FALTANTE = 'faltante';

    public const RESULTADO_PENDIENTE = 'pendiente';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'entrega_id',
        'ticket_item_id',
        'codigo',
        'nombre',
        'cantidad_solicitada',
        'cantidad_entregada',
        'unidad',
        'resultado',
        'motivo',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_solicitada' => 'float',
            'cantidad_entregada' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Entrega, $this>
     */
    public function entrega(): BelongsTo
    {
        return $this->belongsTo(Entrega::class);
    }
}
