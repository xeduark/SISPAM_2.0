<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Entrega extends Model
{
    public const TIPO_PRESENCIAL = 'presencial';

    public const TIPO_DOMICILIO = 'domicilio';

    public const ESTADO_EN_PROCESO = 'en_proceso';

    public const ESTADO_PARCIAL = 'parcial';

    public const ESTADO_COMPLETADA = 'completada';

    public const ESTADO_ANULADA = 'anulada';

    public const FACTURACION_NO_APLICA = 'no_aplica';

    public const FACTURACION_PENDIENTE = 'pendiente';

    public const FACTURACION_MARCADA = 'marcada';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ticket_numero',
        'paciente_id',
        'sede_id',
        'usuario_id',
        'tipo',
        'estado',
        'receptor_nombre',
        'receptor_documento',
        'receptor_parentesco',
        'firma_path',
        'observaciones',
        'facturacion_estado',
        'factura_referencia',
    ];

    /**
     * @return BelongsTo<Paciente, $this>
     */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    /**
     * @return BelongsTo<Sede, $this>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * @return HasMany<EntregaItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(EntregaItem::class);
    }

    /**
     * @return HasOne<DomicilioEnvio, $this>
     */
    public function domicilioEnvio(): HasOne
    {
        return $this->hasOne(DomicilioEnvio::class);
    }

    public function esParcial(): bool
    {
        // Cualquier ítem con cantidad aún por entregar deja la entrega parcial.
        return $this->items()
            ->whereColumn('cantidad_entregada', '<', 'cantidad_solicitada')
            ->exists();
    }

    public function recalcularEstado(): void
    {
        if ($this->estado === self::ESTADO_ANULADA) {
            return;
        }

        $this->estado = $this->esParcial()
            ? self::ESTADO_PARCIAL
            : self::ESTADO_COMPLETADA;
        $this->save();
    }
}
