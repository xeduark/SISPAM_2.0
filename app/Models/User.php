<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable;

    /** Datos administrativos: quién entra, con qué rol y a qué sede. */
    public const CAMPOS_AUDITADOS = [
        'nombre',
        'apellido',
        'documento',
        'email',
        'sede_id',
        'activo',
        'es_administrador',
        'roles',
    ];

    public const ETIQUETA_AUDITORIA = 'usuario';

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

    /**
     * Las sedes en las que esta persona tiene permiso de trabajar.
     *
     * Distinto de `sede()`, que es **dónde está ahora**. La mayoría tendrá una
     * sola y no verá el selector de la barra.
     *
     * @return BelongsToMany<Sede, $this>
     */
    public function sedes(): BelongsToMany
    {
        return $this->belongsToMany(Sede::class)->withTimestamps();
    }

    /**
     * Entre cuáles puede moverse, ya ordenadas.
     *
     * El administrador se mueve por todas sin que nadie se las asigne: ya ve
     * todas las sedes en cada listado, así que limitarle el selector sería
     * incoherente.
     *
     * Se incluye siempre la sede actual aunque nadie se la haya asignado: si
     * un administrador se la quitó mientras la persona estaba trabajando, el
     * selector tiene que seguir diciendo dónde está en vez de quedar en blanco.
     *
     * @return Collection<int, Sede>
     */
    public function sedesDondePuedeTrabajar(): Collection
    {
        if ($this->es_administrador) {
            return Sede::query()->where('activa', true)->orderBy('nombre')->get();
        }

        return Sede::query()
            ->where(fn ($consulta) => $consulta
                // Las que le asignaron, mientras sigan abiertas.
                ->whereIn('sedes.id', $this->sedes()->where('activa', true)->select('sedes.id'))
                // Y donde está ahora, activa o no: si la cerraron mientras
                // trabajaba, el selector tiene que seguir diciendo dónde está.
                ->orWhere('sedes.id', $this->sede_id))
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Si tiene a dónde moverse. Con una sola sede el selector no se muestra.
     */
    public function puedeCambiarDeSede(): bool
    {
        return $this->sedesDondePuedeTrabajar()->count() > 1;
    }

    /**
     * Se muda a otra sede.
     *
     * Escribe `sede_id`, que es lo que lee todo el sistema —tickets, turnos,
     * entrega, transcripción e inventario—, así que el cambio vale para todo
     * de inmediato y no hace falta una «sede activa» aparte.
     *
     * Devuelve false si la sede no está entre las suyas: el selector solo
     * ofrece las permitidas, pero el id viaja por la petición.
     */
    public function cambiarDeSede(Sede $sede): bool
    {
        if (! $this->sedesDondePuedeTrabajar()->contains('id', $sede->getKey())) {
            return false;
        }

        if ((int) $this->sede_id === (int) $sede->getKey()) {
            return true;
        }

        $anterior = $this->sede;

        $this->sede_id = $sede->getKey();
        $this->save();
        $this->setRelation('sede', $sede);

        // Queda quién se movió y a dónde: desde ese momento ve y atiende lo de
        // la sede nueva, y conviene poder reconstruirlo.
        Auditoria::registrar(
            accion: Auditoria::ACCION_CAMBIO_DE_SEDE,
            descripcion: 'Cambió de sede: '.($anterior?->etiqueta ?? 'sin sede').' → '.$sede->etiqueta,
            entidadTipo: 'usuario',
            entidadId: $this->getKey(),
            usuario: $this,
            sedeId: $sede->getKey(),
        );

        return true;
    }

    public function getNombreCompletoAttribute(): string
    {
        return "{$this->nombre} {$this->apellido}";
    }

    public function descripcionParaAuditoria(): string
    {
        return "el usuario {$this->documento}";
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
