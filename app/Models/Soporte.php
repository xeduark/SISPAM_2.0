<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Orden médica que carga el orientador al atender al paciente.
 */
class Soporte extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'orden_medica',
        'alto_costo_oncologico',
        'cargado_por',
    ];

    protected function casts(): array
    {
        return [
            'alto_costo_oncologico' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Paciente, $this>
     */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cargadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cargado_por');
    }
}
