<?php

use App\Http\Controllers\Auth\AuthentikController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

Route::middleware('guest')->controller(AuthentikController::class)->group(function () {
    Route::get('/auth/authentik/redirect', 'redirect')->name('auth.authentik.redirect');
    Route::get('/auth/authentik/callback', 'callback')->name('auth.authentik.callback');
});
