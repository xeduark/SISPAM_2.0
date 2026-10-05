<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ColaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cola de atención de una sede.
 *
 * **El prefijo ya no arma el turno.** Desde que el turno es solo el
 * consecutivo («0060»), el prefijo queda como etiqueta corta de la cola: es lo
 * que distingue «Dispensación general (A)» de «Alto costo y oncológicos (B)»
 * en el filtro de Llamar turnos. Quién numera es la sede, no la cola; ver
 * `App\Services\Turnos\GeneradorDeTurnos`.
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
     * Los tickets que salieron por esta cola.
     *
     * Es lo que dice si ya se usó. Antes se miraba `contadores_turno`, pero
     * ese contador pasó a ser de la sede: una cola recién creada en una sede
     * que ya atendió hoy aparecería como usada sin haber entregado nada.
     *
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
