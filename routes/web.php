<?php

use App\Http\Controllers\ActaEntregaController;
use App\Http\Controllers\Auth\AuthentikController;
use App\Http\Controllers\OrdenEntregaController;
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

// Orden de dispensación imprimible del ticket (fase 3 de transcripción).
Route::middleware('auth')->get('/tickets/{ticket}/orden-entrega', [OrdenEntregaController::class, 'mostrar'])
    ->name('tickets.orden-entrega');

// Acta de una entrega: lo entregado con lote, pendientes, lo que no se dispensa y la firma.
Route::middleware('auth')->get('/entregas/{entrega}/acta', [ActaEntregaController::class, 'mostrar'])
    ->name('entregas.acta');

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
