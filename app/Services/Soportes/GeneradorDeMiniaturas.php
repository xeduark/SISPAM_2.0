<?php

namespace App\Services\Soportes;

use App\Models\Soporte;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Hace la miniatura de una fórmula, para que la galería cargue rápido.
 *
 * Usa GD, que ya viene con PHP: no hace falta una dependencia nueva. No lee
 * PDF —para eso haría falta imagick con ghostscript—, así que esos se quedan
 * sin miniatura y la galería les pone un ícono.
 *
 * ## Se genera cuando alguien la pide, no al guardar
 *
 * Redimensionar una imagen de 2000 px toma del orden de 100 ms. Hacerlo al
 * registrar la visita metería ese tiempo dentro de la transacción, justo
 * cuando el paciente está esperando en el mostrador, y por una imagen que tal
 * vez nadie mire. Hacerlo al pedirla sale gratis para el orientador, cubre los
 * soportes que ya existían sin un comando de relleno, y solo paga el costo la
 * primera vez que alguien abre esa ficha.
 */
class GeneradorDeMiniaturas
{
    /** Lado largo de la miniatura. Suficiente para reconocer la hoja. */
    public const LADO = 400;

    /** Donde viven, dentro del mismo disco privado que los originales. */
    public const DIRECTORIO = 'soportes/ordenes-medicas/miniaturas';

    /**
     * La ruta de la miniatura, generándola si todavía no existe.
     *
     * Devuelve null cuando no se puede hacer: un PDF, un archivo que ya no
     * está, un formato que GD no lee o una imagen rota. La galería lo
     * resuelve mostrando el ícono del tipo de archivo, así que un fallo aquí
     * nunca deja la ficha en blanco.
     */
    public function para(Soporte $soporte): ?string
    {
        $disco = Storage::disk('local');

        if (filled($soporte->miniatura) && $disco->exists($soporte->miniatura)) {
            return $soporte->miniatura;
        }

        if ($soporte->esPdf() || blank($soporte->orden_medica) || ! $disco->exists($soporte->orden_medica)) {
            return null;
        }

        $miniatura = $this->reducir($disco->path($soporte->orden_medica));

        if ($miniatura === null) {
            return null;
        }

        $ruta = self::DIRECTORIO.'/'.Str::ulid().'.jpg';
        $disco->put($ruta, $miniatura);

        // `saveQuietly` para no ensuciar la auditoría: de la fórmula ya quedó
        // registrado que se cargó, y hacer su miniatura no es un cambio que
        // nadie haya decidido.
        $soporte->miniatura = $ruta;
        $soporte->saveQuietly();

        return $ruta;
    }

    /**
     * Los bytes de la imagen reducida, o null si GD no pudo con ella.
     */
    private function reducir(string $rutaAbsoluta): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $contenido = @file_get_contents($rutaAbsoluta);

        if ($contenido === false) {
            return null;
        }

        $original = @imagecreatefromstring($contenido);

        if ($original === false) {
            return null;
        }

        try {
            $ancho = imagesx($original);
            $alto = imagesy($original);

            if ($ancho < 1 || $alto < 1) {
                return null;
            }

            // Nunca se agranda: una foto ya pequeña se queda como está.
            $escala = min(1, self::LADO / max($ancho, $alto));
            $nuevoAncho = max(1, (int) round($ancho * $escala));
            $nuevoAlto = max(1, (int) round($alto * $escala));

            $miniatura = imagecreatetruecolor($nuevoAncho, $nuevoAlto);

            // Fondo blanco: los PNG con transparencia saldrían negros en JPEG.
            imagefill($miniatura, 0, 0, imagecolorallocate($miniatura, 255, 255, 255));
            imagecopyresampled($miniatura, $original, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

            ob_start();
            imagejpeg($miniatura, null, 78);
            $bytes = ob_get_clean();

            imagedestroy($miniatura);

            return $bytes ?: null;
        } finally {
            imagedestroy($original);
        }
    }
}
