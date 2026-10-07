<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntregaItem extends Model
{
    public const RESULTADO_ENTREGADO = 'entregado';

    /** Se entregó algo, pero queda cantidad por completar. */
    public const RESULTADO_PARCIAL = 'parcial';

    /** No había existencias en el momento. */
    public const RESULTADO_FALTANTE = 'faltante';

    /** Se aplaza la entrega (no es faltante de stock). */
    public const RESULTADO_PENDIENTE = 'pendiente';

    /**
     * @return array<string, string>
     */
    public static function resultados(): array
    {
        return [
            self::RESULTADO_ENTREGADO => 'Entregado completo',
            self::RESULTADO_PARCIAL => 'Entrega parcial',
            self::RESULTADO_FALTANTE => 'Faltante (sin existencias)',
            self::RESULTADO_PENDIENTE => 'Aplazado (se entrega después)',
        ];
    }

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
        'cantidad_pendiente',
        'unidad',
        'resultado',
        'motivo',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_solicitada' => 'float',
            'cantidad_entregada' => 'float',
            'cantidad_pendiente' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Entrega, $this>
     */
    public function entrega(): BelongsTo
    {
        return $this->belongsTo(Entrega::class);
    }

    public function quedaPendiente(): bool
    {
        return $this->cantidad_pendiente > 0;
    }
}
