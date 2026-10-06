<?php

namespace App\Http\Controllers;

use App\Models\Soporte;
use App\Services\Soportes\GeneradorDeMiniaturas;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve la miniatura de una fórmula para la galería de la ficha.
 *
 * Exige lo mismo que el original —sesión y el permiso `orientacion.ver_orden`—
 * y sale del mismo disco privado. Nunca hay URL pública: una miniatura de una
 * fórmula sigue siendo un dato de salud.
 *
 * ## Por qué esta ruta no audita cada imagen
 *
 * `OrdenMedicaController` deja una línea por cada apertura, y así debe ser:
 * ahí alguien **lee** la fórmula. Si las miniaturas hicieran lo mismo, abrir
 * la ficha de un paciente con diez hojas dejaría diez líneas de «abrió la
 * orden médica» sin que nadie haya leído nada, y el rastro de quién sí la
 * leyó quedaría enterrado entre el ruido.
 *
 * Una miniatura de 400 px no se lee. Lo que queda registrado es **haber
 * entrado a la galería**, una sola vez, desde `ViewPaciente`; y leer una hoja
 * concreta se sigue registrando una por una, como siempre.
 */
class MiniaturaDeFormulaController extends Controller
{
    public function mostrar(Soporte $soporte, GeneradorDeMiniaturas $miniaturas): StreamedResponse
    {
        abort_unless(
            (bool) auth()->user()?->puede('orientacion.ver_orden'),
            403,
            'No tienes permiso para ver órdenes médicas.',
        );

        $ruta = $miniaturas->para($soporte);

        // Un PDF o una imagen que GD no pudo leer: la galería lo resuelve con
        // el ícono del tipo de archivo.
        abort_if($ruta === null, 404, 'Esa fórmula no tiene miniatura.');

        return Storage::disk('local')->response($ruta, 'miniatura.jpg', [
            'Content-Type' => 'image/jpeg',
            // Solo para este navegador y por un rato: el permiso se comprueba
            // en cada petición, así que no conviene que viva en una caché
            // compartida.
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
