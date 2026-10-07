<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * El cierre del día: lo que nadie alcanzó a atender queda «vencido».
 *
 * Corre de madrugada, cuando ya no hay nadie en la sala, y cierra hasta el día
 * anterior. Como solo mira días pasados, **da igual si un día no corre**: a la
 * mañana siguiente recoge lo que quedó. Por eso no hace falta vigilar que la
 * tarea no se haya saltado nunca.
 *
 * Esto necesita que algo ejecute `php artisan schedule:run` cada minuto (cron
 * en Linux, Programador de tareas en Windows). Si eso no está montado,
 * `php artisan tickets:cerrar-dia` a mano hace exactamente lo mismo.
 */
Schedule::command('tickets:cerrar-dia')
    ->dailyAt('02:00')
    ->withoutOverlapping();
