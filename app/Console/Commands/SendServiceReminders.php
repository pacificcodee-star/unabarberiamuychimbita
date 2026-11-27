<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Turn;
use App\Services\TurnQueueService;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;

class SendServiceReminders extends Command
{
    protected $signature = 'app:send-service-reminders';

    protected $description = 'Envía recordatorios de WhatsApp antes de terminar citas y turnos';

    public function handle(WhatsAppService $whatsApp, TurnQueueService $queue): int
    {
        $now = now();
        $sent = 0;

        // Citas: 15 minutos antes de acabar el servicio, contadas desde la llegada verificada.
        $appointments = Appointment::query()
            ->with(['service', 'barber'])
            ->whereNull('reminder_sent_at')
            ->whereNotNull('client_phone')
            ->where('attendance_status', 'presente')
            ->whereNotNull('started_at')
            ->whereIn('status', ['pendiente', 'confirmada'])
            ->whereDate('appointment_date', $now->toDateString())
            ->get();

        foreach ($appointments as $appointment) {
            $end = $appointment->started_at->copy()->addMinutes($appointment->durationMinutes());
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

        // Turnos: 10 minutos antes de terminar, desde el inicio de la atención.
        $turns = Turn::query()
            ->with(['service', 'barber'])
            ->whereNull('reminder_sent_at')
            ->whereNotNull('client_phone')
            ->where('status', 'atendiendo')
            ->where(fn ($query) => $query->where('attendance_status', 'presente')->orWhereNull('attendance_status'))
            ->whereNotNull('started_at')
            ->whereDate('turn_date', $now->toDateString())
            ->get();

        foreach ($turns as $turn) {
            $start = $turn->started_at;
            $end = $start->copy()->addMinutes($queue->durationMinutes($turn));
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
