<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Turn;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendServiceReminders extends Command
{
    protected $signature = 'app:send-service-reminders';

    protected $description = 'Envía recordatorios de WhatsApp antes de terminar citas y turnos';

    public function handle(WhatsAppService $whatsApp): int
    {
        $now = now();
        $sent = 0;

        // Citas: 15 minutos antes de acabar el corte (fin estimado = hora cita + duración del servicio).
        $appointments = Appointment::query()
            ->with(['service', 'barber'])
            ->whereNull('reminder_sent_at')
            ->whereNotNull('client_phone')
            ->whereIn('status', ['pendiente', 'confirmada'])
            ->whereDate('appointment_date', $now->toDateString())
            ->get();

        foreach ($appointments as $appointment) {
            $duration = (int) ($appointment->service?->duration_minutes ?: 30);
            $start = Carbon::parse($appointment->appointment_date->toDateString().' '.substr((string) $appointment->appointment_time, 0, 5));
            $end = $start->copy()->addMinutes($duration);
            $triggerAt = $end->copy()->subMinutes(15);

            // Ventana: desde el disparo hasta el fin (o hasta 5 min después por si el cron se retrasa), sin enviar si ya terminó hace rato.
            if ($now->lt($triggerAt) || $now->gte($end->copy()->addMinutes(5))) {
                continue;
            }

            $barber = $appointment->barber?->name ?: 'tu barbero';
            $horaFin = $end->format('h:i A');
            $msg = "Hola {$appointment->client_name}, te recordamos que tu cita en la barbería con {$barber} está por finalizar (aprox. {$horaFin}). ¡Gracias por tu visita!";
            if ($whatsApp->sendText($appointment->client_phone, $msg)) {
                $appointment->forceFill(['reminder_sent_at' => $now])->save();
                $sent++;
            } else {
                // Marcar intento para no spamear en cada minuto cuando no hay API; si se configura API, quitar esta marca manualmente o ajustar.
                // Solo marcamos si realmente se intentó con API configurada (sendText ya loguea). Para evitar reintentos infinitos sin API, no marcamos aquí.
            }
        }

        // Turnos: 10 minutos antes de terminar el servicio (en atención, fin estimado = called_at + duración).
        $turns = Turn::query()
            ->with(['service', 'barber'])
            ->whereNull('reminder_sent_at')
            ->whereNotNull('client_phone')
            ->where('status', 'atendiendo')
            ->whereNotNull('called_at')
            ->whereDate('turn_date', $now->toDateString())
            ->get();

        foreach ($turns as $turn) {
            $duration = (int) ($turn->service?->duration_minutes ?: 30);
            $start = Carbon::parse($turn->called_at);
            $end = $start->copy()->addMinutes($duration);
            $triggerAt = $end->copy()->subMinutes(10);

            if ($now->lt($triggerAt) || $now->gte($end->copy()->addMinutes(5))) {
                continue;
            }

            $barber = $turn->barber?->name ?: 'tu barbero';
            $horaFin = $end->format('h:i A');
            $msg = "Hola {$turn->client_name}, te recordamos que tu turno en la barbería con {$barber} está por finalizar (aprox. {$horaFin}). ¡Gracias por tu visita!";
            if ($whatsApp->sendText($turn->client_phone, $msg)) {
                $turn->forceFill(['reminder_sent_at' => $now])->save();
                $sent++;
            }
        }

        $this->info("Recordatorios enviados: {$sent}");
        return self::SUCCESS;
    }
}
