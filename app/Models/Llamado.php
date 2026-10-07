<?php

namespace App\Models;

use Database\Factories\LlamadoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un llamado del turno en la sala.
 *
 * Es el rastro de quién llamó a qué turno, desde cuál ventanilla y en qué
 * intento. No se audita en `auditorias` porque esta tabla **es** el registro:
 * duplicarlo solo llenaría la auditoría de ruido.
 */
class Llamado extends Model
{
    /** @use HasFactory<LlamadoFactory> */
    use HasFactory;

    protected $table = 'llamados';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ticket_id',
        'sede_id',
        'cola_id',
        'ventanilla_id',
        'llamado_por',
        'intento',
    ];

    protected function casts(): array
    {
        return [
            'intento' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
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
    public function llamadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'llamado_por');
    }
}
