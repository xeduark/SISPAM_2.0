<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DomicilioEnvio extends Model
{
    public const ESTADO_PENDIENTE_ENVIO = 'pendiente_envio';

    public const ESTADO_PREPARADO = 'preparado';

    public const ESTADO_ENVIADO_DOMINA = 'enviado_domina';

    public const ESTADO_EN_RUTA = 'en_ruta';

    public const ESTADO_ENTREGADO = 'entregado';

    public const ESTADO_NO_ENTREGADO = 'no_entregado';

    public const ESTADO_NOVEDAD = 'novedad';

    /**
     * @return array<string, string>
     */
    public static function estados(): array
    {
        return [
            self::ESTADO_PENDIENTE_ENVIO => 'Pendiente de envío',
            self::ESTADO_PREPARADO => 'Preparado',
            self::ESTADO_ENVIADO_DOMINA => 'Enviado a Dómina',
            self::ESTADO_EN_RUTA => 'En ruta',
            self::ESTADO_ENTREGADO => 'Entregado',
            self::ESTADO_NO_ENTREGADO => 'No entregado',
            self::ESTADO_NOVEDAD => 'Novedad',
        ];
    }

    /**
     * Transiciones internas permitidas (hasta tener la API de Dómina).
     *
     * @return array<string, list<string>>
     */
    public static function transicionesPermitidas(): array
    {
        return [
            self::ESTADO_PENDIENTE_ENVIO => [self::ESTADO_PREPARADO],
            self::ESTADO_PREPARADO => [self::ESTADO_ENVIADO_DOMINA],
            self::ESTADO_ENVIADO_DOMINA => [self::ESTADO_EN_RUTA],
            self::ESTADO_EN_RUTA => [
                self::ESTADO_ENTREGADO,
                self::ESTADO_NO_ENTREGADO,
                self::ESTADO_NOVEDAD,
            ],
            self::ESTADO_NOVEDAD => [
                self::ESTADO_EN_RUTA,
                self::ESTADO_NO_ENTREGADO,
                self::ESTADO_PREPARADO,
            ],
            self::ESTADO_ENTREGADO => [],
            self::ESTADO_NO_ENTREGADO => [self::ESTADO_PREPARADO, self::ESTADO_NOVEDAD],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function siguientesEstados(): array
    {
        $permitidos = self::transicionesPermitidas()[$this->estado] ?? [];

        return collect(self::estados())
            ->only($permitidos)
            ->all();
    }

    public function puedePasarA(string $estadoNuevo): bool
    {
        return in_array($estadoNuevo, self::transicionesPermitidas()[$this->estado] ?? [], true);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'entrega_id',
        'telefono',
        'direccion',
        'barrio',
        'ciudad',
        'indicaciones_entrega',
        'estado',
        'referencia_externa',
        'novedad_detalle',
    ];

    /**
     * @return BelongsTo<Entrega, $this>
     */
    public function entrega(): BelongsTo
    {
        return $this->belongsTo(Entrega::class);
    }

    /**
     * @return HasMany<DomicilioEstadoHistorial, $this>
     */
    public function historial(): HasMany
    {
        return $this->hasMany(DomicilioEstadoHistorial::class)->latest('id');
    }
}
