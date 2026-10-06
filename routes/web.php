<?php

use App\Http\Controllers\Auth\AuthentikController;
use App\Http\Controllers\MiniaturaDeFormulaController;
use App\Http\Controllers\OrdenMedicaController;
use App\Http\Controllers\SalaController;
use App\Http\Controllers\TicketImpresionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

/*
 * Orden médica: archivo privado, nunca una URL pública. Exige sesión y el
 * permiso `orientacion.ver_orden`, y cada acceso queda en la auditoría.
 */
Route::middleware('auth')->get('/soportes/{soporte}/orden-medica', [OrdenMedicaController::class, 'mostrar'])
    ->name('soportes.orden-medica');

/*
 * La miniatura de la fórmula, para la galería de la ficha. Mismo permiso y
 * mismo disco privado que el original; lo que no hace es dejar una línea de
 * auditoría por imagen, porque una miniatura de 400 px no se lee. Entrar a la
 * galería sí queda registrado, una sola vez. Ver `MiniaturaDeFormulaController`.
 */
Route::middleware('auth')->get('/soportes/{soporte}/miniatura', [MiniaturaDeFormulaController::class, 'mostrar'])
    ->name('soportes.miniatura');

/*
 * El ticket impreso que se lleva el paciente. Exige sesión y el permiso
 * `tickets.imprimir` —aparte de `tickets.ver`, porque el orientador genera el
 * ticket pero no entra al listado—, y solo deja imprimir lo de la sede propia.
 * Nunca sale nada del paciente ni de su salud. Ver `TicketImpresionController`.
 */
Route::middleware('auth')->get('/tickets/{ticket}/imprimir', [TicketImpresionController::class, 'mostrar'])
    ->name('tickets.imprimir');

/*
 * Pantalla de la sala de espera. Es **pública a propósito**: el televisor de la
 * sala no inicia sesión. Solo publica turno y ventanilla; nunca el nombre del
 * paciente ni nada de la fórmula. Ver `SalaController`.
 */
Route::controller(SalaController::class)->group(function () {
    Route::get('/sala/{sede:codigo}', 'mostrar')->name('sala');
    Route::get('/sala/{sede:codigo}/turnos', 'turnos')->name('sala.turnos');
});

Route::middleware('guest')->controller(AuthentikController::class)->group(function () {
    Route::get('/auth/authentik/redirect', 'redirect')->name('auth.authentik.redirect');
    Route::get('/auth/authentik/callback', 'callback')->name('auth.authentik.callback');
});
