<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:send-service-reminders')->everyMinute();

// Fila automática de turnos + avisos y recordatorios a clientes. Necesita el programador encendido:
//   en tu computador:  php artisan schedule:work
//   en el servidor:    * * * * * cd /ruta/del/proyecto && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('barberia:tick')->everyMinute()->withoutOverlapping(5);
