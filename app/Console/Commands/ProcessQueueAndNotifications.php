<?php

namespace App\Console\Commands;

use App\Services\AttendanceAutomation;
use App\Services\ClientNotifier;
use App\Services\TurnQueueService;
use Illuminate\Console\Command;

class ProcessQueueAndNotifications extends Command
{
    protected $signature = 'barberia:tick';

    protected $description = 'Avanza la fila automática de turnos y envía los avisos y recordatorios que ya deben salir';

    public function handle(TurnQueueService $queue, ClientNotifier $notifier, AttendanceAutomation $attendance): int
    {
        $queue->advanceAll();
        $queue->remindUpcoming();
        // Sin confirmación 15 min después del recordatorio → cancelar; cita confirmada y terminada → atendida + gracias.
        $result = $attendance->run();
        $sent = $notifier->processDue();

        $this->info('Fila revisada. Canceladas sin confirmar: '.$result['cancelled'].'. Citas finalizadas: '.$result['attended'].'. Avisos enviados: '.$sent);

        return self::SUCCESS;
    }
}
