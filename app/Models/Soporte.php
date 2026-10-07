<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Orden médica que carga el orientador al atender al paciente.
 */
class Soporte extends Model
{
    use Auditable;

    /**
     * Vacío a propósito: de la orden médica solo interesa saber que se cargó
     * y para qué paciente. Ni la ruta del archivo ni la marca de alto costo
     * u oncológico entran al rastro, porque son datos de salud.
     */
    public const CAMPOS_AUDITADOS = [];

    public const ETIQUETA_AUDITORIA = 'orden_medica';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ticket_id',
        'orden_medica',
        'pagina',
        'mime',
        'miniatura',
        'alto_costo_oncologico',
        'cargado_por',
    ];

    protected function casts(): array
    {
        return [
            'alto_costo_oncologico' => 'boolean',
            'pagina' => 'integer',
        ];
    }

    /**
     * Si la fórmula es un PDF del escáner y no una foto.
     *
     * Decide el visor: las imágenes se amplían dentro de la pantalla, los PDF
     * se abren en el del navegador. Se mira el `mime` guardado y, si falta
     * —los soportes anteriores a esta columna lo tienen en null—, la extensión.
     */
    public function esPdf(): bool
    {
        if (filled($this->mime)) {
            return str_contains(strtolower((string) $this->mime), 'pdf');
        }

        return strtolower(pathinfo((string) $this->orden_medica, PATHINFO_EXTENSION)) === 'pdf';
    }

    public function esImagen(): bool
    {
        return ! $this->esPdf();
    }

    public function descripcionParaAuditoria(): string
    {
        return 'una orden médica del paciente '.($this->paciente?->documento_completo ?? 'sin identificar');
    }

    /**
     * El ticket de la visita en la que se cargó esta orden.
     *
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
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
