<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomicilioEstadoHistorial extends Model
{
    protected $table = 'domicilio_estado_historial';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'domicilio_envio_id',
        'estado_anterior',
        'estado_nuevo',
        'usuario_id',
        'nota',
    ];

    /**
     * @return BelongsTo<DomicilioEnvio, $this>
     */
    public function domicilioEnvio(): BelongsTo
    {
        return $this->belongsTo(DomicilioEnvio::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
