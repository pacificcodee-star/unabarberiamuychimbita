<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ClientNotification;
use App\Models\StaffNotification;
use App\Models\Turn;
use App\Support\AttendanceConfirmation;
use App\Support\ReservationStatusHistory;
use Illuminate\Support\Facades\DB;

/**
 * Reglas automáticas de asistencia (corre cada minuto con barberia:tick):
 *
 *  1. Recordatorio enviado por WhatsApp y el cliente NO confirmó en 15 minutos
 *     → la cita o el turno se cancela y se le avisa por WhatsApp.
 *  2. Cita confirmada por el cliente → al cumplirse la hora + la duración del servicio
 *     queda "atendida", se registra el ingreso y se le envía el mensaje de gracias.
 *     (Los turnos ya se cierran solos en TurnQueueService; ahí también sale el gracias.)
 *
 * Las citas agendadas faltando menos de 15 minutos se confirman solas (ClientNotifier::appointmentBooked).
 * Solo cuenta lo de hoy en adelante: nada de días anteriores se toca.
 */
class AttendanceAutomation
{
    public function __construct(
        private ClientNotifier $notifier,
        private TurnQueueService $queue,
        private AttendedServiceRecorder $recorder,
    ) {
    }

    public function confirmMinutes(): int
    {
        return max(1, (int) config('notificaciones.confirm_window_minutes', 15));
    }

    /** @return array{cancelled:int, attended:int} */
    public function run(): array
    {
        $cancelled = $this->cancelUnconfirmed();
        $attended = $this->finishConfirmedAppointments();
        $this->notifier->flush();

        return ['cancelled' => $cancelled, 'attended' => $attended];
    }

    /** Regla 1: recordatorio de WhatsApp enviado hace 15 minutos o más y sin confirmación → cancelar. */
    public function cancelUnconfirmed(): int
    {
        $limit = now()->subMinutes($this->confirmMinutes());
        $count = 0;

        $reminders = ClientNotification::query()
            ->where('channel', 'whatsapp')
            ->where('status', ClientNotification::SENT)
            ->whereIn('type', array_merge(ClientNotifier::REMINDER_TYPES, ['turn_reminder']))
            ->whereNotNull('sent_at')
            ->where('sent_at', '<=', $limit)
            ->where('sent_at', '>=', now()->subDay())
            ->orderBy('sent_at')
            ->get(['notifiable_type', 'notifiable_id', 'type'])
            ->unique(fn ($n) => $n->notifiable_type.':'.$n->notifiable_id);

        foreach ($reminders as $reminder) {
            if ($reminder->type === 'turn_reminder') {
                $turn = Turn::with(['barber', 'service'])->find($reminder->notifiable_id);
                $count += $turn && $this->cancelTurn($turn) ? 1 : 0;
            } else {
                $appointment = Appointment::with(['barber', 'service'])->find($reminder->notifiable_id);
                $count += $appointment && $this->cancelAppointment($appointment) ? 1 : 0;
            }
        }

        return $count;
    }

    private function cancelAppointment(Appointment $appointment): bool
    {
        $updated = DB::transaction(function () use ($appointment) {
            $current = Appointment::whereKey($appointment->id)->lockForUpdate()->first();
            if (! $current || ! in_array($current->status, ['pendiente', 'confirmada'], true)
                || AttendanceConfirmation::confirmed($current)
                || $current->appointment_date->toDateString() < now()->toDateString()) {
                return false;
            }
            return $current->update(['status' => 'cancelada']);
        });

        if (! $updated) {
            return false;
        }

        $appointment->refresh();
        $this->notifier->cancelledWithoutConfirmation($appointment, $this->confirmMinutes());

        StaffNotification::record(
            'client_cancelled',
            'Cita cancelada: el cliente no confirmó',
            ($appointment->client_name ?: $appointment->client_phone).' · '.$appointment->startsAt()->format('d/m h:i A')
                .'. No respondió el recordatorio en '.$this->confirmMinutes().' minutos.',
            route('appointments.index', ['date' => $appointment->appointment_date?->toDateString()], false),
            $appointment->barber_id,
        );

        return true;
    }

    private function cancelTurn(Turn $turn): bool
    {
        if (! in_array($turn->status, ['esperando', 'llamado'], true)
            || AttendanceConfirmation::confirmed($turn)
            || ! $turn->turn_date?->isSameDay(now())) {
            return false;
        }

        $updated = Turn::query()
            ->whereKey($turn->id)
            ->whereIn('status', ['esperando', 'llamado'])
            ->update(['status' => 'cancelado', 'finished_at' => now(), 'updated_at' => now()]);

        if (! $updated) {
            return false;
        }

        $turn->refresh();
        $this->notifier->cancelledWithoutConfirmation($turn, $this->confirmMinutes());

        StaffNotification::record(
            'client_cancelled',
            'Turno #'.$turn->turn_number.' anulado: el cliente no confirmó',
            ($turn->client_name ?: $turn->client_phone).'. No respondió el recordatorio en '.$this->confirmMinutes().' minutos.',
            route('turns.index', [], false),
            $turn->barber_id,
        );

        // La fila se reorganiza para que pase el siguiente.
        $this->queue->advance((int) $turn->barber_id);

        return true;
    }

    /** Regla 2: cita confirmada cuyo servicio ya terminó (hora + duración) → atendida + ingreso + gracias. */
    public function finishConfirmedAppointments(): int
    {
        $count = 0;

        $appointments = Appointment::query()
            ->with(['barber', 'service'])
            ->where('status', 'confirmada')
            ->whereDate('appointment_date', now()->toDateString())
            ->get();

        foreach ($appointments as $appointment) {
            if (! AttendanceConfirmation::confirmed($appointment)) {
                continue; // solo las que el cliente confirmó (o se confirmaron solas al agendar sobre la hora)
            }

            $endsAt = $appointment->startsAt()->addMinutes($appointment->durationMinutes());

            if (now()->lt($endsAt)) {
                continue;
            }

            $done = DB::transaction(function () use ($appointment) {
                $updated = Appointment::query()
                    ->whereKey($appointment->id)
                    ->where('status', 'confirmada')
                    ->whereDate('appointment_date', $appointment->appointment_date->toDateString())
                    ->whereTime('appointment_time', $appointment->startsAt()->format('H:i:s'))
                    ->update(['status' => 'atendida', 'updated_at' => now()]);

                if (! $updated) {
                    return false;
                }

                $appointment->refresh();
                ReservationStatusHistory::record($appointment, 'confirmada');
                $this->recorder->sync($appointment, $appointment->payment_method);
                // Se marcó con una actualización directa (sin eventos): se programa la encuesta a mano.
                app(\App\Services\Surveys\SurveyService::class)->statusChanged($appointment, 'confirmada');

                return true;
            });

            if (! $done) {
                continue;
            }

            $this->notifier->serviceThanks($appointment);

            StaffNotification::record(
                'appointment_finished',
                'Cita finalizada automáticamente: '.($appointment->client_name ?: $appointment->client_phone),
                'Se cumplió el tiempo del servicio'.($appointment->sale_id ? ' y se registró el ingreso.' : '.').' Si el cliente no llegó, cámbiala a cancelada.',
                route('appointments.index', ['date' => $appointment->appointment_date?->toDateString()], false),
                $appointment->barber_id,
            );

            $count++;
        }

        return $count;
    }
}
