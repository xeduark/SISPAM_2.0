<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'nombre',
        'apellido',
        'documento',
        'email',
        'sede_id',
        'activo',
        'roles',
        'es_administrador',
    ];

    /** Permisos de la matriz ya resueltos, para no consultarlos en cada verificación. */
    private ?array $permisosResueltos = null;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'roles' => 'array',
            'es_administrador' => 'boolean',
        ];
    }

    /**
     * Si la matriz de permisos le deja hacer `modulo.accion` (p. ej. «pacientes.crear»).
     * Los roles son los grupos de Authentik; el administrador puede todo.
     */
    public function puede(string $permiso): bool
    {
        if ($this->es_administrador) {
            return true;
        }

        [$modulo, $accion] = explode('.', $permiso, 2) + [1 => null];

        $this->permisosResueltos ??= Rol::whereIn('nombre', $this->roles ?? [])
            ->pluck('permisos')
            ->reduce(fn (array $todos, ?array $permisos): array => array_merge_recursive($todos, $permisos ?? []), []);

        return in_array($accion, $this->permisosResueltos[$modulo] ?? [], true);
    }

    /**
     * @return BelongsTo<Sede, $this>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function getNombreCompletoAttribute(): string
    {
        return "{$this->nombre} {$this->apellido}";
    }

    /**
     * Solo los usuarios activos pueden ingresar al panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->activo;
    }

    public function getFilamentName(): string
    {
        return $this->nombre_completo;
    }
}
