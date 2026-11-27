<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\StaffNotification;
use App\Models\Turn;
use App\Models\User;
use App\Support\ReservationStatusHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceVerificationService
{
    public function __construct(
        private ClientNotifier $notifier,
        private TurnQueueService $queue,
    ) {
    }

    public function dueAt(Appointment|Turn $record): Carbon
    {
        return $record instanceof Turn ? $this->queue->expectedStart($record) : $record->startsAt();
    }

    public function canVerify(Appointment|Turn $record, ?Carbon $at = null, ?string $decision = null): bool
    {
        $at ??= now();

        if ($record->attendance_status !== null || $record->sale_id || $this->dueAt($record)->gt($at)) {
            return false;
        }

        if ($record instanceof Turn) {
            return $record->turn_date?->isSameDay($at) && $record->status === 'llamado';
        }

        $today = $record->appointment_date?->isSameDay($at);
        $overdueAbsence = $decision === 'ausente'
            && $record->appointment_date?->toDateString() < $at->toDateString();

        return in_array($record->status, ['pendiente', 'confirmada'], true) && ($today || $overdueAbsence);
    }

    /** @return array{ok:bool,message:string,attendance_status:?string,started_at:?string} */
    public function verify(User $admin, Appointment|Turn $record, string $decision): array
    {
        $decision = match ($decision) {
            'presente', 'ausente' => $decision,
            default => throw ValidationException::withMessages(['decision' => 'Indica si el cliente llegó o no llegó.']),
        };

        $previousStatus = (string) $record->status;
        $applied = false;
        $result = DB::transaction(function () use ($admin, $record, $decision, &$applied) {
            $current = $record::query()->lockForUpdate()->find($record->getKey());
            if (! $current) {
                abort(404);
            }

            if ($current->attendance_status !== null) {
                if ($current->attendance_status !== $decision) {
                    throw ValidationException::withMessages(['decision' => 'La asistencia de este servicio ya fue registrada.']);
                }

                return [
                    'ok' => true,
                    'message' => 'La asistencia de este servicio ya estaba registrada.',
                    'attendance_status' => $current->attendance_status,
                    'started_at' => $current->started_at?->format('H:i'),
                ];
            }

            if (! $this->canVerify($current, decision: $decision)) {
                $message = match (true) {
                    $current instanceof Turn && $current->status !== 'llamado' => 'Solo se verifica la llegada del turno que está llamado.',
                    $current instanceof Appointment && $decision === 'presente' && ! $current->appointment_date?->isSameDay(now()) => 'La llegada solo se puede verificar el mismo día de la cita.',
                    default => 'La verificación de llegada está disponible cuando el servicio esté por iniciar.',
                };

                throw ValidationException::withMessages(['decision' => $message]);
            }

            $from = (string) $current->status;
            $now = now();
            $attributes = [
                'attendance_status' => $decision,
                'attendance_verified_at' => $now,
                'attendance_verified_by' => $admin->id,
            ];

            if ($current instanceof Turn) {
                if ($decision === 'presente') {
                    $attributes['status'] = 'atendiendo';
                    $attributes['started_at'] = $now;
                } else {
                    $attributes['status'] = 'cancelado';
                    $attributes['finished_at'] = $now;
                }
            } elseif ($decision === 'presente') {
                $attributes['status'] = 'confirmada';
                $attributes['started_at'] = $now;
            } else {
                $attributes['status'] = 'cancelada';
            }

            $current->update($attributes);
            $applied = true;
            ReservationStatusHistory::record($current, $from);
            $current->loadMissing(['barber', 'service']);

            return [
                'ok' => true,
                'message' => $decision === 'presente'
                    ? 'Llegada verificada. El servicio inició en este momento.'
                    : 'Ausencia registrada. El servicio fue cancelado sin generar ingreso.',
                'attendance_status' => $decision,
                'started_at' => $current->started_at?->format('H:i'),
            ];
        });

        $record->refresh();

        if ($decision === 'ausente' && $applied) {
            if ($record instanceof Turn) {
                $this->notifier->turnCancelled($record);
                $this->queue->advance((int) $record->barber_id);
            } else {
                $this->notifier->appointmentChanged($record, [
                    'status' => $previousStatus,
                    'starts_at' => $this->dueAt($record),
                ]);
                $this->notifier->flush();
            }
        }

        return $result;
    }

    public function promptDue(): int
    {
        $now = now();
        $prompted = 0;

        $appointments = Appointment::query()
            ->with(['barber', 'service'])
            ->whereIn('status', ['pendiente', 'confirmada'])
            ->whereNull('attendance_status')
            ->whereNull('attendance_prompted_at')
            ->whereDate('appointment_date', $now->toDateString())
            ->get();

        foreach ($appointments as $appointment) {
            if ($this->dueAt($appointment)->gt($now) || ! $this->prompt($appointment)) {
                continue;
            }

            StaffNotification::record(
                'attendance_check',
                'Verifica la llegada de '.($appointment->client_name ?: 'la cita'),
                'La cita de las '.$this->dueAt($appointment)->format('H:i').' está por iniciar. Marca si el cliente llegó o no llegó.',
                route('appointments.index', ['date' => $appointment->appointment_date->toDateString(), 'attendance' => $appointment->id], false),
            );
            $prompted++;
        }

        $turns = Turn::query()
            ->with(['barber', 'service'])
            ->where('status', 'llamado')
            ->whereNull('attendance_status')
            ->whereNull('attendance_prompted_at')
            ->whereDate('turn_date', $now->toDateString())
            ->get();

        foreach ($turns as $turn) {
            if ($this->dueAt($turn)->gt($now) || ! $this->prompt($turn)) {
                continue;
            }

            StaffNotification::record(
                'attendance_check',
                'Verifica la llegada del turno #'.$turn->turn_number,
                ($turn->client_name ?: 'El cliente').' debe pasar ahora. Marca si llegó o no llegó para continuar la cola.',
                route('turns.index', ['date' => $turn->turn_date->toDateString(), 'attendance' => $turn->id], false),
            );
            $prompted++;
        }

        return $prompted;
    }

    private function prompt(Appointment|Turn $record): bool
    {
        return (bool) $record::query()
            ->whereKey($record->getKey())
            ->whereNull('attendance_status')
            ->whereNull('attendance_prompted_at')
            ->update(['attendance_prompted_at' => now(), 'updated_at' => now()]);
    }
}
