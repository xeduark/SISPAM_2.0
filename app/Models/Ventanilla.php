<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\VentanillaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Puesto de atención de una sede. Atiende cualquiera de sus colas: quien
 * llama elige de cuál, así una ventanilla nunca queda ociosa.
 */
class Ventanilla extends Model
{
    /** @use HasFactory<VentanillaFactory> */
    use Auditable, HasFactory;

    public const CAMPOS_AUDITADOS = ['sede_id', 'nombre', 'activa', 'orden'];

    public const ETIQUETA_AUDITORIA = 'ventanilla';

    protected $table = 'ventanillas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sede_id',
        'nombre',
        'activa',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    public function descripcionParaAuditoria(): string
    {
        return "la ventanilla «{$this->nombre}» de {$this->sede?->nombre}";
    }

    /**
     * @return BelongsTo<Sede, $this>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }
}
