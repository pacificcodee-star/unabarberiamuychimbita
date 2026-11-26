<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\StaffNotification;
use App\Models\Turn;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceArrival
{
    public function __construct(private TurnQueueService $queue, private ClientNotifier $notifier) {}

    public function isDue(Appointment|Turn $service): bool
    {
        if ($service instanceof Turn) {
            return $service->status === 'llamado' && $service->turn_date->isToday()
                && $service->called_at && now()->gte($this->queue->expectedStart($service));
        }

        return in_array($service->status, ['pendiente', 'confirmada'], true)
            && $service->appointment_date->isToday() && now()->gte($service->startsAt());
    }

    public function decide(Appointment|Turn $service, bool $present): void
    {
        DB::transaction(function () use ($service, $present) {
            User::whereKey($service->barber_id)->lockForUpdate()->firstOrFail();
            $current = $service->newQuery()->lockForUpdate()->findOrFail($service->id);
            if ($current->barber_id !== $service->barber_id || ! $this->isDue($current)) {
                throw ValidationException::withMessages(['arrival' => 'Esta reserva ya cambió o aún no es hora de confirmar su llegada.']);
            }

            $turn = $current instanceof Turn;
            $current->status = $present ? ($turn ? 'atendiendo' : 'en_atencion') : 'no_asistio';
            if ($present) {
                $current->started_at = now();
            } elseif ($turn) {
                $current->finished_at = now();
            }
            $current->save();
            $this->notifier->cancelPending($current);
            AuditLog::record($present ? 'asistencia.llego' : 'asistencia.no_llego', $current);
        }, 3);

        if ($service instanceof Turn && ! $present) {
            $this->queue->advance($service->barber_id);
        }
    }

    public function notifyDue(): void
    {
        $appointments = Appointment::whereDate('appointment_date', today())->whereIn('status', ['confirmada', 'pendiente'])
            ->whereTime('appointment_time', '<=', now()->format('H:i:s'));
        $turns = Turn::whereDate('turn_date', today())->where('status', 'llamado')
            ->where('called_at', '<=', now()->subMinutes($this->queue->graceMinutes()));
        foreach ([$appointments, $turns] as $query) {
            $query->whereNull('arrival_notified_at')->eachById(function ($service) {
                DB::transaction(function () use ($service) {
                    $current = $service->newQuery()->lockForUpdate()->find($service->id);
                    if (! $current || $current->arrival_notified_at || ! $this->isDue($current)) {
                        return;
                    }
                    $turn = $current instanceof Turn;
                    $label = $turn ? 'Turno #'.$current->turn_number : 'Cita de las '.$current->startsAt()->format('g:i a');
                    $url = route($turn ? 'turns.index' : 'appointments.index', ['date' => today()->toDateString()], false);
                    if (StaffNotification::record('arrival_due', $label.' · '.$current->client_name,
                        'Es hora de iniciar el servicio. Confirma si llegó a la barbería.', $url.'#llegada-'.$current->id)) {
                        $current->forceFill(['arrival_notified_at' => now()])->saveQuietly();
                    }
                }, 3);
            });
        }
    }
}
