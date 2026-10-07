<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\SedeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sede extends Model
{
    /** @use HasFactory<SedeFactory> */
    use Auditable, HasFactory;

    public const CAMPOS_AUDITADOS = ['nombre', 'codigo', 'direccion', 'telefono', 'activa'];

    public const ETIQUETA_AUDITORIA = 'sede';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nombre',
        'codigo',
        'direccion',
        'telefono',
        'activa',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    public function descripcionParaAuditoria(): string
    {
        return "la sede «{$this->nombre}»";
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Cola, $this>
     */
    public function colas(): HasMany
    {
        return $this->hasMany(Cola::class);
    }

    /**
     * @return HasMany<Ventanilla, $this>
     */
    public function ventanillas(): HasMany
    {
        return $this->hasMany(Ventanilla::class);
    }
}
