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

    /* ------------------------------------------------- La etiqueta */

    /*
     * La sede nunca se nombra sola: siempre va con su código.
     *
     * El código es lo que el paciente ve impreso en su ticket
     * (`SP-PRP-20261005-A023`) y lo que distingue dos sedes de nombre
     * parecido. Que la etiqueta se arme **en un solo sitio** es lo que
     * garantiza que el selector de Usuarios, el filtro de Tickets y el ticket
     * impreso digan exactamente lo mismo.
     */

    /** «PREMIUM PLAZA (PRP)», o solo el nombre si todavía no tiene código. */
    public function getEtiquetaAttribute(): string
    {
        return filled($this->codigo)
            ? "{$this->nombre} ({$this->codigo})"
            : (string) $this->nombre;
    }

    /**
     * Opciones para un selector o un filtro de sede, ya etiquetadas.
     *
     * @param  iterable<int, self>|null  $sedes  Las que se quieran listar; por defecto, todas
     * @return array<int, string>
     */
    public static function opciones(?iterable $sedes = null): array
    {
        $sedes ??= static::query()->orderBy('nombre')->get();

        $opciones = [];
        foreach ($sedes as $sede) {
            $opciones[$sede->getKey()] = $sede->etiqueta;
        }

        return $opciones;
    }

    /**
     * Las columnas por las que se busca una sede en un selector.
     *
     * Quien atiende se sabe el código antes que el nombre completo, así que
     * escribir «PRP» tiene que encontrar PREMIUM PLAZA.
     *
     * @return list<string>
     */
    public static function columnasDeBusqueda(): array
    {
        return ['nombre', 'codigo'];
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
