<?php

use App\Http\Controllers\Auth\AuthentikController;
use App\Http\Controllers\OrdenMedicaController;
use App\Http\Controllers\SalaController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

/*
 * Orden médica: archivo privado, nunca una URL pública. Exige sesión y el
 * permiso `orientacion.ver_orden`, y cada acceso queda en la auditoría.
 */
Route::middleware('auth')->get('/soportes/{soporte}/orden-medica', [OrdenMedicaController::class, 'mostrar'])
    ->name('soportes.orden-medica');

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
