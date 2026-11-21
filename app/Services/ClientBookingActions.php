<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\StaffNotification;
use App\Models\Turn;
use App\Models\User;
use App\Support\AttendanceConfirmation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientBookingActions
{
    public function __construct(private ClientNotifier $notifier, private TurnQueueService $queue) {}

    public static function actions(Appointment|Turn $subject): array
    {
        if ($subject instanceof Turn) {
            return $subject->turn_date->isToday() && in_array($subject->status, ['esperando', 'llamado'], true)
                ? ['cancelar'] : [];
        }

        if (! in_array($subject->status, ['pendiente', 'confirmada'], true) || ! $subject->startsAt()->isFuture()) {
            return [];
        }

        $actions = ['reprogramar', 'cancelar'];
        if ($subject->status === 'pendiente' || ! AttendanceConfirmation::confirmed($subject)) {
            array_unshift($actions, 'confirmar');
        }

        return $actions;
    }

    public function apply(Appointment|Turn $subject, string $action, array $data = []): string
    {
        if ($subject instanceof Turn) {
            if ($action !== 'cancelar') {
                throw ValidationException::withMessages(['status' => 'Esta acción no está disponible para turnos.']);
            }
            $this->queue->advance($subject->barber_id, $subject);
            StaffNotification::record('client_cancelled', 'Turno #'.$subject->turn_number.' cancelado por el cliente',
                $subject->client_name.' canceló su turno.', route('turns.index', [], false), $subject->barber_id);

            return 'Tu turno fue cancelado.';
        }

        $appointment = DB::transaction(function () use ($subject, $action, $data) {
            // Mismo orden de bloqueo que las altas de agenda, incluso con dos reprogramaciones simultáneas.
            $barber = User::whereKey($subject->barber_id)->lockForUpdate()->firstOrFail();
            $appointment = Appointment::whereKey($subject->id)->lockForUpdate()->firstOrFail();
            if ($action === 'cancelar' && $appointment->status === 'cancelada') {
                throw ValidationException::withMessages(['status' => 'Tu cita ya estaba cancelada.']);
            }
            if ($appointment->barber_id !== $barber->id || ! in_array($action, self::actions($appointment), true)) {
                throw ValidationException::withMessages(['status' => 'La cita cambió o ya no admite esta acción. Revisa su estado.']);
            }

            $before = ['status' => $appointment->status, 'starts_at' => $appointment->startsAt()];
            if ($action === 'reprogramar') {
                if (! in_array($data['appointment_time'], Appointment::availableTimes($barber, $data['appointment_date']), true)) {
                    throw ValidationException::withMessages(['appointment_time' => 'Ese horario ya no está disponible. Elige otro.']);
                }
                Appointment::reserveSlot([
                    'barber_id' => $barber->id,
                    'appointment_date' => $data['appointment_date'],
                    'appointment_time' => $data['appointment_time'],
                    'status' => 'confirmada',
                    'reminder_sent_at' => null,
                ], $appointment);
            } else {
                $appointment->update(['status' => $action === 'cancelar' ? 'cancelada' : 'confirmada']);
            }

            if ($action === 'cancelar') {
                AttendanceConfirmation::forget($appointment);
            } else {
                AttendanceConfirmation::mark($appointment);
            }
            $this->notifier->appointmentChanged($appointment, $before);

            return $appointment;
        }, 3);

        [$event, $label] = match ($action) {
            'confirmar' => ['client_confirmed', 'confirmada'],
            'reprogramar' => ['appt_rescheduled', 'reprogramada'],
            'cancelar' => ['client_cancelled', 'cancelada'],
        };
        StaffNotification::record($event, 'Cita '.$label.' por el cliente',
            $appointment->client_name.' · '.$appointment->startsAt()->format('d/m/Y h:i A'),
            route('appointments.index', ['date' => $appointment->appointment_date->toDateString()], false), $appointment->barber_id);
        $this->notifier->flush();

        return 'Tu cita quedó '.$label.'.';
    }
}
