<?php

use App\Models\GuestCredential;
use App\Models\GuestVerificationCode;
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

// Copias WebP livianas de las fotos que se suban desde el panel (el original no se toca; ver ConvertImagesToWebp).
Schedule::command('barberia:webp --solo-storage')->everyFiveMinutes()->withoutOverlapping(30)->runInBackground();

// Avisos del equipo de más de 90 días: ya nadie los consulta y solo hacen crecer la tabla.
Schedule::call(function (): void {
    \App\Models\StaffNotification::where('created_at', '<', now()->subDays(\App\Models\StaffNotification::RETENTION_DAYS))->delete();
})->daily()->name('limpiar-avisos-equipo');

// Retención mínima: códigos de recuperación viejos y credenciales inactivas hace más de 30 días.
Schedule::call(function (): void {
    GuestVerificationCode::where('created_at', '<', now()->subDays(7))->delete();
    GuestCredential::where(fn ($query) => $query->where('expires_at', '<', now()->subDays(30))
        ->orWhere(fn ($inactive) => $inactive->whereNotNull('revoked_at')->where('revoked_at', '<', now()->subDays(30))))->delete();
})->daily()->name('limpiar-recuperacion-invitados');
