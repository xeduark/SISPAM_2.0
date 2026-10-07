<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Medicamento de un ticket, capturado por farmacia al alistar.
 */
class TicketItem extends Model
{
    protected $table = 'ticket_items';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ticket_id',
        'codigo',
        'nombre',
        'cantidad',
        'unidad',
        'observacion',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
