<?php

namespace App\Models\Concerns;

use App\Models\Auditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * Deja rastro en la tabla `auditorias` de lo que le pasa a un modelo.
 *
 * El modelo que lo use debe declarar:
 *
 *   public const CAMPOS_AUDITADOS = ['estado_afiliacion', 'regimen'];
 *   public const ETIQUETA_AUDITORIA = 'paciente';
 *
 * `CAMPOS_AUDITADOS` es una lista BLANCA: **lo que no esté ahí no se
 * registra**. Es a propósito. Si mañana alguien agrega un campo clínico al
 * modelo, no entra al rastro por olvido: hay que declararlo. La protección
 * está en la estructura, igual que `Paciente::CAMPOS_SAVIA` con el contacto.
 *
 * Para que la descripción sea legible sin exponer datos sensibles, el modelo
 * puede definir `descripcionParaAuditoria()`. Si no, se usa su id.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $modelo): void {
            Auditoria::registrar(
                accion: Auditoria::ACCION_CREO,
                descripcion: static::frase('Creó', $modelo),
                entidadTipo: static::etiquetaAuditoria(),
                entidadId: $modelo->getKey(),
            );
        });

        static::updated(function (Model $modelo): void {
            $cambios = static::cambiosAuditables($modelo);

            // Si no cambió nada de la lista blanca no hay nada que contar.
            if ($cambios === []) {
                return;
            }

            Auditoria::registrar(
                accion: Auditoria::ACCION_ACTUALIZO,
                descripcion: static::frase('Actualizó', $modelo),
                entidadTipo: static::etiquetaAuditoria(),
                entidadId: $modelo->getKey(),
                cambios: $cambios,
            );
        });

        static::deleted(function (Model $modelo): void {
            Auditoria::registrar(
                accion: Auditoria::ACCION_ELIMINO,
                descripcion: static::frase('Eliminó', $modelo),
                entidadTipo: static::etiquetaAuditoria(),
                entidadId: $modelo->getKey(),
            );
        });
    }

    /**
     * Qué cambió, solo de los campos declarados.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    protected static function cambiosAuditables(Model $modelo): array
    {
        $cambios = [];

        foreach (static::camposAuditados() as $campo) {
            if (! $modelo->wasChanged($campo)) {
                continue;
            }

            $cambios[$campo] = [
                static::comoTextoAuditable($modelo->getOriginal($campo)),
                static::comoTextoAuditable($modelo->getAttribute($campo)),
            ];
        }

        return $cambios;
    }

    /**
     * Valores legibles y seguros de serializar en el JSON de la auditoría.
     */
    protected static function comoTextoAuditable(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $texto = match (true) {
            is_bool($valor) => $valor ? 'Sí' : 'No',
            $valor instanceof \DateTimeInterface => $valor->format('Y-m-d H:i'),
            // Una lista simple se lee mejor separada por comas; una estructura
            // anidada (los permisos de un rol, por ejemplo) como JSON.
            is_array($valor) => count(array_filter($valor, 'is_scalar')) === count($valor)
                ? implode(', ', $valor)
                : (string) json_encode($valor, JSON_UNESCAPED_UNICODE),
            default => (string) $valor,
        };

        return mb_substr($texto, 0, 180);
    }

    protected static function frase(string $verbo, Model $modelo): string
    {
        $que = method_exists($modelo, 'descripcionParaAuditoria')
            ? $modelo->descripcionParaAuditoria()
            : static::etiquetaAuditoria().' #'.$modelo->getKey();

        return "{$verbo} {$que}";
    }

    /**
     * @return list<string>
     */
    protected static function camposAuditados(): array
    {
        return defined(static::class.'::CAMPOS_AUDITADOS') ? static::CAMPOS_AUDITADOS : [];
    }

    protected static function etiquetaAuditoria(): string
    {
        return defined(static::class.'::ETIQUETA_AUDITORIA')
            ? static::ETIQUETA_AUDITORIA
            : class_basename(static::class);
    }
}
