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
        'cuenta_compartida',
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
        'cuenta_compartida',
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
            'cuenta_compartida' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        /*
         * Una cuenta compartida nunca es administradora, y tiene siempre el
         * rol CONSULTA y solo ese:
         *
         * - Administradora no, porque no se sabe quién está detrás, y el
         *   administrador ve todo y edita la matriz.
         * - Solo CONSULTA, venga lo que venga de Authentik: el inicio de
         *   sesión copia sus grupos aquí, y si alguien la mete en otro grupo
         *   allá no gana más permisos. Si no está en el grupo CONSULTA, igual
         *   lo tiene. Lo que puede hacer se ajusta en la matriz, en ese rol.
         *
         * Se garantiza aquí y no solo en el formulario, para que ningún camino
         * (el inicio de sesión, un seeder, tinker) deje pasar otra cosa.
         * `permisos()` lo vuelve a imponer al leer, por si la fila se tocó
         * directo en la base.
         */
        static::saving(function (User $usuario): void {
            if ($usuario->cuenta_compartida) {
                $usuario->es_administrador = false;
                $usuario->roles = [Rol::CONSULTA];
            }
        });
    }

    /**
     * Si la matriz de permisos le deja hacer `modulo.accion` (p. ej. «pacientes.crear»).
     * Los roles son los grupos de Authentik; el administrador puede todo.
     *
     * Antes que todo, el módulo tiene que estar encendido en esta instalación
     * (`SISPAM_MODULOS`): uno apagado no existe ni para el administrador.
     * Como todas las pantallas, recursos, acciones y archivos preguntan aquí,
     * apagarlo en este sitio lo apaga en todos.
     */
    public function puede(string $permiso): bool
    {
        [$modulo, $accion] = explode('.', $permiso, 2) + [1 => null];

        if (! Rol::moduloEncendido($modulo)) {
            return false;
        }

        if ($this->es_administrador) {
            return true;
        }

        return in_array($accion, $this->permisos()[$modulo] ?? [], true);
    }

    /**
     * Si lo único que puede hacer en SISPAM es consultar pacientes en Savia.
     *
     * Es el caso del rol CONSULTA. A esa persona el panel la lleva directo a
     * «Consultar paciente» y no le muestra el Escritorio. Sale de la matriz y
     * no del nombre del rol: si el administrador le da algo más, vuelve a
     * tener Escritorio como los demás. Lo de módulos apagados no cuenta: no
     * lo puede usar.
     */
    public function soloPuedeConsultar(): bool
    {
        if ($this->es_administrador) {
            return false;
        }

        $modulos = array_keys(array_filter(
            $this->permisos(),
            fn (?array $acciones, string $modulo): bool => filled($acciones) && Rol::moduloEncendido($modulo),
            ARRAY_FILTER_USE_BOTH,
        ));

        return $modulos === ['consultar_paciente'] && $this->puede('consultar_paciente.ver');
    }

    /**
     * Los roles que cuentan para sus permisos. Una cuenta compartida tiene
     * CONSULTA y nada más, diga lo que diga `users.roles`.
     *
     * @return list<string>
     */
    public function rolesEfectivos(): array
    {
        return $this->cuenta_compartida ? [Rol::CONSULTA] : array_values((array) ($this->roles ?? []));
    }

    /**
     * Los permisos de todos sus roles, sumados: ['pacientes' => ['ver', 'crear'], …].
     *
     * @return array<string, list<string>>
     */
    private function permisos(): array
    {
        return $this->permisosResueltos ??= Rol::whereIn('nombre', $this->rolesEfectivos())
            ->pluck('permisos')
            ->reduce(fn (array $todos, ?array $permisos): array => array_merge_recursive($todos, $permisos ?? []), []);
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
        // Una cuenta compartida es la de una sede: la usan varias personas a
        // la vez y todas tienen que estar en el mismo sitio. Si una la moviera,
        // las demás quedarían de pronto trabajando en otra sede.
        if ($this->cuenta_compartida) {
            return Sede::query()->whereKey($this->sede_id)->get();
        }

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
